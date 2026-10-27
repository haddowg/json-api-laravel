<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Scopes;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiLaravel\Scope\ScopeTables;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Tests\Fixtures\Scopes\BookReadScopes;
use haddowg\JsonApiLaravel\Tests\Fixtures\Scopes\ScopesServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * How each server's scope table is built, kept and read back: from the discovery snapshot
 * `jsonapi:optimize` writes, from the cache store without one, and live for an entry a
 * stale table lacks. Also the include rules the table drives at request time, and the
 * builds that must fail.
 *
 * @internal
 */
#[CoversNothing]
final class ScopeTablesTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    private string $snapshot = '';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ScopesServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->snapshot = \sys_get_temp_dir() . '/jsonapi-scopes-' . \bin2hex(\random_bytes(6)) . '/discovery.php';

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.discovery.paths', []);
        $config->set('jsonapi.discovery.cache', $this->snapshot);
        $config->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        if (\is_file($this->snapshot)) {
            \unlink($this->snapshot);
            @\rmdir(\dirname($this->snapshot));
        }

        parent::tearDown();
    }

    public function test_a_default_include_the_caller_may_not_read_is_left_out(): void
    {
        $response = $this->read('/api/shelves/1', 'reader#')->assertOk();
        self::assertSame(['type' => 'books', 'id' => '1'], $response->json('data.relationships.book.data'));
        self::assertNull($response->json('included'));

        $this->read('/api/shelves/1', 'reader#books:read')->assertOk()->assertJsonPath('included.0.id', '1');
    }

    public function test_an_explicit_include_the_caller_may_not_read_is_refused(): void
    {
        $this->read('/api/shelves/1?include=book', 'reader#')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_SCOPE')
            ->assertJsonPath('errors.0.meta.include', 'book');
    }

    public function test_a_related_read_needs_the_returned_types_read_scopes(): void
    {
        $this->read('/api/shelves/1/book', 'reader#')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.meta.requiredScopes', ['books:read']);
    }

    public function test_a_public_operation_given_a_scope_fails_the_build(): void
    {
        $this->resolver()->scopeNotes = true;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/public/i');

        $this->tables()->tableFor('default');
    }

    public function test_optimize_fails_when_the_resolver_throws(): void
    {
        $this->resolver()->fail = true;

        self::assertSame(1, Artisan::call('jsonapi:optimize'));
        self::assertStringContainsString('scope table build failed', Artisan::output());
    }

    public function test_optimize_fails_when_a_public_operation_is_given_a_scope(): void
    {
        $this->resolver()->scopeNotes = true;

        self::assertSame(1, Artisan::call('jsonapi:optimize'));
        self::assertStringContainsString('notes', Artisan::output());
    }

    public function test_the_snapshot_carries_each_servers_table_and_is_read_back_without_the_resolver(): void
    {
        $code = Artisan::call("jsonapi:optimize");
        self::assertSame(0, $code, Artisan::output());

        /** @var array{scopes: array<string, array{types: array<string, array<string, list<string>>>}>} $written */
        $written = require $this->snapshot;
        self::assertSame(['books:read'], $written['scopes']['default']['types']['books']['fetchOne']);
        self::assertSame(['books:read'], $written['scopes']['default']['types']['shelves']['fetchRelated.book']);

        $this->rebootScopes();
        $this->resolver()->calls = 0;

        self::assertSame(['books:read'], $this->tables()->tableFor('default')->for('books', 'fetchCollection'));
        $this->read('/api/books/1', 'reader#')->assertStatus(403);
        self::assertSame(0, $this->resolver()->calls);
    }

    public function test_without_a_snapshot_the_table_is_kept_in_the_cache_store(): void
    {
        $this->tables()->tableFor('default');
        $calls = $this->resolver()->calls;
        self::assertGreaterThan(0, $calls);

        $this->app?->forgetInstance(ScopeTables::class);

        self::assertSame(['books:read'], $this->tables()->tableFor('default')->for('books', 'fetchOne'));
        self::assertSame($calls, $this->resolver()->calls);

        Artisan::call('jsonapi:clear');
        $this->app?->forgetInstance(ScopeTables::class);
        $this->tables()->tableFor('default');
        self::assertGreaterThan($calls, $this->resolver()->calls);
    }

    public function test_an_entry_a_stale_table_lacks_is_resolved_live_and_never_opened(): void
    {
        $code = Artisan::call("jsonapi:optimize");
        self::assertSame(0, $code, Artisan::output());

        /** @var array{scopes: array<string, array{types: array<string, array<string, list<string>>>}>} $written */
        $written = require $this->snapshot;
        unset($written['scopes']['default']['types']['books']['fetchOne']);
        \file_put_contents($this->snapshot, '<?php return ' . \var_export($written, true) . ';');

        $this->rebootScopes();
        $log = Log::spy();

        $this->read('/api/books/1', 'reader#')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.meta.requiredScopes', ['books:read']);
        $this->read('/api/books/1', 'reader#books:read')->assertOk();

        $log->shouldHaveReceived('warning')->withArgs(static fn(string $message): bool => \str_contains($message, 'no entry'));
    }

    private function resolver(): BookReadScopes
    {
        $resolver = $this->app?->make(BookReadScopes::class);
        self::assertInstanceOf(BookReadScopes::class, $resolver);

        return $resolver;
    }

    private function tables(): ScopeTables
    {
        $tables = $this->app?->make(ScopeTables::class);
        self::assertInstanceOf(ScopeTables::class, $tables);

        return $tables;
    }

    /**
     * Drops the services built before the snapshot was written, as a new process would.
     */
    private function rebootScopes(): void
    {
        foreach ([Discovery::class, ScopeTables::class, ServerRegistry::class, MetadataSource::class] as $service) {
            $this->app?->forgetInstance($service);
        }
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, string $token): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }
}
