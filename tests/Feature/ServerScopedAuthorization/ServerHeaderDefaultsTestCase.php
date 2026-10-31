<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ServerScopedAuthorization;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization\ServerScopedAuthorizationServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * A server's own `defaults` block over global defaults of a public 5s cache and
 * `Deprecation: true`. The `admin` server sets a sunset and its link and a 45s cache varying
 * on Accept-Language; `default` sets nothing. `labels` declares no headers on either server,
 * admin `notes` cache publicly for 600s with their own sunset, and admin `documents` cache
 * privately for 30s.
 *
 * @internal
 */
abstract class ServerHeaderDefaultsTestCase extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public const string MEDIA_TYPE = 'application/vnd.api+json';

    public const string SUNSET = 'Sat, 01 May 2027 00:00:00 GMT';

    public const string SUNSET_LINK = 'https://example.test/v6';

    /**
     * `eloquent` or `memory`.
     */
    abstract protected function store(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('ssa.store', $this->store());
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin-api', 'middleware' => [], 'domain' => null, 'defaults' => [
                'sunset' => self::SUNSET,
                'sunset_link' => self::SUNSET_LINK,
                'cache_headers' => ['max_age' => 45, 'vary' => ['Accept-Language']],
            ]],
        ]);
        $config->set('jsonapi.defaults', [
            'cache_headers' => ['max_age' => 5, 'public' => true],
            'deprecation' => true,
        ]);

        return [JsonApiServiceProvider::class, ServerScopedAuthorizationServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(new User(['id' => 2, 'name' => 'Admin', 'is_admin' => true]));
    }

    #[Test]
    #[Group('headers')]
    public function aServersDefaultsApplyOnlyToThatServersResponses(): void
    {
        $admin = $this->read('/admin-api/labels/1');
        self::assertSame(self::SUNSET, $this->header($admin, 'Sunset'));
        self::assertSame(\sprintf('<%s>; rel="sunset"', self::SUNSET_LINK), $this->header($admin, 'Link'));

        $default = $this->read('/api/labels/1');
        self::assertFalse($default->headers->has('Sunset'));
        self::assertFalse($default->headers->has('Link'));
    }

    #[Test]
    #[Group('headers')]
    public function aResourcesOwnValueOverridesItsServersDefaults(): void
    {
        $notes = $this->read('/admin-api/notes/1');
        self::assertSame('Wed, 11 Nov 2026 00:00:00 GMT', $this->header($notes, 'Sunset'));
        self::assertStringContainsString('max-age=600', $this->header($notes, 'Cache-Control'));
        // The class sets no sunset link, so the server's still rides beside its own sunset.
        self::assertSame(\sprintf('<%s>; rel="sunset"', self::SUNSET_LINK), $this->header($notes, 'Link'));
    }

    #[Test]
    #[Group('headers')]
    public function aServersDefaultsMergeWithTheGlobalOnesFieldByField(): void
    {
        $admin = $this->read('/admin-api/labels/1');

        // The server sets no deprecation, so the global one applies beside its sunset.
        self::assertSame('true', $this->header($admin, 'Deprecation'));
        self::assertSame(self::SUNSET, $this->header($admin, 'Sunset'));
    }

    #[Test]
    #[Group('headers')]
    public function aServerWithNoDefaultsOfItsOwnUsesTheGlobalOnes(): void
    {
        $default = $this->read('/api/labels/1');
        self::assertStringContainsString('max-age=5', $this->header($default, 'Cache-Control'));
        self::assertStringContainsString('public', $this->header($default, 'Cache-Control'));
        self::assertSame('true', $this->header($default, 'Deprecation'));
        self::assertStringNotContainsString('Accept-Language', $this->header($default, 'Vary'));
    }

    #[Test]
    #[Group('headers')]
    public function cacheHeadersLayerResourceOverServerOverGlobal(): void
    {
        $labels = $this->read('/admin-api/labels/1');
        self::assertStringContainsString('max-age=45', $this->header($labels, 'Cache-Control'));
        // public comes from the global defaults, which the server block leaves alone.
        self::assertStringContainsString('public', $this->header($labels, 'Cache-Control'));
        self::assertStringContainsString('Accept-Language', $this->header($labels, 'Vary'));

        // The class's own max_age and privacy win, and the server's Vary is still added.
        $documents = $this->read('/admin-api/documents/1');
        self::assertStringContainsString('max-age=30', $this->header($documents, 'Cache-Control'));
        self::assertStringContainsString('private', $this->header($documents, 'Cache-Control'));
        self::assertStringContainsString('Accept-Language', $this->header($documents, 'Vary'));
    }

    #[Test]
    #[Group('openapi')]
    public function theOpenApiDocumentsDescribeNoneOfTheseHeaders(): void
    {
        foreach (['default', 'admin'] as $server) {
            $json = \json_encode($this->resolve(DocumentFactory::class)->forServer($server)->toArray(), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('"deprecated":true', $json);
            self::assertStringNotContainsString('"Sunset"', $json);
            self::assertStringNotContainsString('"Deprecation"', $json);
        }
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE])->assertOk();
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function header(TestResponse $response, string $name): string
    {
        return (string) $response->headers->get($name, '');
    }
}
