<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Scopes;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Scopes\TrackReadScopesServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\User;
use Workbench\App\MusicCatalog\Support\CatalogConfig;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * OAuth scope enforcement over the music catalogue, on both data layers. The grant is read
 * off the example's bearer token, `alice#<scopes>`, and the resolver adds `tracks:read` to
 * the catalogue's write scopes so includes and related reads can be refused too.
 *
 * @internal
 */
#[CoversNothing]
abstract class ScopeEnforcementTestCase extends Orchestra
{
    use PinsCatalogClock;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @return class-string
     */
    abstract protected function catalogServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->catalogServiceProvider(),
            TrackReadScopesServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        CatalogConfig::apply($config);
    }

    public function test_a_write_without_its_scope_is_refused_with_the_challenge(): void
    {
        $response = $this->write('POST', '/api/playlists', $this->playlist(), 'alice#tracks:read')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_SCOPE')
            ->assertJsonPath('errors.0.meta.requiredScopes', ['library:write'])
            ->assertJsonPath('errors.0.meta.missingScopes', ['library:write']);

        self::assertSame('Bearer error="insufficient_scope", scope="library:write"', $response->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith(self::MEDIA_TYPE, (string) $response->headers->get('Content-Type'));
    }

    public function test_a_write_with_its_scope_runs(): void
    {
        $this->write('POST', '/api/playlists', $this->playlist(), 'alice#library:write')->assertStatus(201);
    }

    public function test_a_token_without_scopes_is_not_checked(): void
    {
        $this->write('POST', '/api/playlists', $this->playlist(), 'alice')->assertStatus(201);
        $this->write('POST', '/api/playlists', $this->playlist(), null)->assertStatus(201);
    }

    public function test_an_override_adds_to_what_the_resolver_requires(): void
    {
        $this->actingAs(new User(['id' => 3, 'name' => 'Admin', 'can_write' => false, 'can_read' => false, 'is_admin' => true]));

        $response = $this->call('DELETE', '/api/playlists/' . Fixtures::PLAYLIST_ONE, [], [], [], $this->server('alice#library:write'))
            ->assertStatus(403)
            ->assertJsonPath('errors.0.meta.requiredScopes', ['library:write', 'library:admin'])
            ->assertJsonPath('errors.0.meta.missingScopes', ['library:admin']);

        self::assertSame('Bearer error="insufficient_scope", scope="library:write library:admin"', $response->headers->get('WWW-Authenticate'));
    }

    public function test_an_action_declaring_scopes_needs_all_of_them(): void
    {
        $this->call('POST', '/api/albums/1/-actions/artwork', [], [], [], $this->server('alice#catalog:write', 'image/png'), 'png')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.meta.missingScopes', ['catalog:media']);
    }

    public function test_a_read_needs_no_scope_but_an_include_of_a_scoped_type_does(): void
    {
        $this->read('/api/albums/1', 'alice#')->assertOk();

        $this->read('/api/albums/1?include=tracks', 'alice#')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_SCOPE')
            ->assertJsonPath('errors.0.source.parameter', 'include')
            ->assertJsonPath('errors.0.meta.include', 'tracks')
            ->assertJsonPath('errors.0.meta.missingScopes', ['tracks:read']);

        $this->read('/api/albums/1?include=tracks', 'alice#tracks:read')->assertOk();
    }

    public function test_a_related_read_needs_the_scopes_of_the_type_it_returns(): void
    {
        $this->read('/api/albums/1/tracks', 'alice#')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.meta.requiredScopes', ['tracks:read']);
        $this->read('/api/albums/1/relationships/tracks', 'alice#')->assertStatus(403);

        $this->read('/api/albums/1/tracks', 'alice#tracks:read')->assertOk();
    }

    public function test_an_atomic_batch_needs_the_endpoint_scope(): void
    {
        $this->atomic([['op' => 'add', 'data' => $this->playlist()['data']]], 'alice#library:write')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.meta.missingScopes', ['batch']);
    }

    public function test_an_atomic_batch_is_refused_before_any_write_when_one_operation_lacks_its_scope(): void
    {
        $before = $this->playlistCount();

        $response = $this->atomic([
            ['op' => 'add', 'data' => $this->playlist()['data']],
            ['op' => 'remove', 'ref' => ['type' => 'albums', 'id' => '1']],
        ], 'alice#batch library:write')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_SCOPE')
            ->assertJsonPath('errors.0.source.pointer', '/atomic:operations/1')
            ->assertJsonPath('errors.0.meta.missingScopes', ['catalog:write']);

        self::assertSame('Bearer error="insufficient_scope", scope="catalog:write"', $response->headers->get('WWW-Authenticate'));
        self::assertSame($before, $this->playlistCount());
    }

    public function test_an_atomic_batch_with_every_scope_runs(): void
    {
        $before = $this->playlistCount();

        $this->atomic([['op' => 'add', 'data' => $this->playlist()['data']]], 'alice#batch library:write')->assertOk();

        self::assertSame($before + 1, $this->playlistCount());
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function playlist(): array
    {
        return ['data' => ['type' => 'playlists', 'attributes' => [
            'title' => 'Scoped',
            'public' => true,
            'externalId' => '3f8a4e1e-2b7d-4b1e-9c69-0a4a2f0e5b10',
        ]]];
    }

    private function playlistCount(): int
    {
        $count = $this->read('/api/playlists?page[size]=50', null)->assertOk()->json('data');
        self::assertIsArray($count);

        return \count($count);
    }

    /**
     * @return array<string, string>
     */
    private function server(?string $token, string $contentType = self::MEDIA_TYPE, string $accept = self::MEDIA_TYPE): array
    {
        $server = ['HTTP_ACCEPT' => $accept, 'CONTENT_TYPE' => $contentType];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return $server;
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, ?string $token): TestResponse
    {
        return $this->call('GET', $uri, [], [], [], $this->server($token));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $document, ?string $token): TestResponse
    {
        return $this->call($method, $uri, [], [], [], $this->server($token), (string) \json_encode($document));
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function atomic(array $operations, string $token): TestResponse
    {
        return $this->call('POST', '/api/operations', [], [], [], $this->server($token, self::ATOMIC_MEDIA_TYPE, self::ATOMIC_MEDIA_TYPE), (string) \json_encode(['atomic:operations' => $operations]));
    }
}
