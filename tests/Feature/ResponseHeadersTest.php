<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Lifecycle\LifecycleServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The declarative response headers (PLAN decision 12): a resource's `#[AsJsonApiResource]`
 * cache directives + RFC 8594 deprecation/sunset are projected onto the response by the
 * {@see \haddowg\JsonApiLaravel\Http\ResponseHeadersMiddleware} — cache on a successful GET
 * only (with a per-read-shape override), deprecation/sunset on every response for the type;
 * a type declaring no headers is untouched. Driven over HTTP on the `relics` fixture.
 *
 * @internal
 */
final class ResponseHeadersTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            LifecycleServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('headers')]
    public function aSuccessfulGetCarriesTheDeclaredCacheAndDeprecationHeaders(): void
    {
        $response = $this->readJsonApi('/api/relics/1')->assertOk();

        $cacheControl = $this->header($response, 'Cache-Control');
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('Accept', $this->header($response, 'Vary'));

        $this->assertSame('true', $this->header($response, 'Deprecation'));
        $this->assertSame('Wed, 11 Nov 2026 00:00:00 GMT', $this->header($response, 'Sunset'));
        $this->assertStringContainsString('rel="sunset"', $this->header($response, 'Link'));
    }

    #[Test]
    #[Group('headers')]
    public function theCollectionReadShapeUsesItsPerOperationCacheOverride(): void
    {
        $response = $this->readJsonApi('/api/relics')->assertOk();

        // The `collection` per-operation override (max_age 3600) layers over the
        // resource-level default (max_age 60).
        $this->assertStringContainsString('max-age=3600', $this->header($response, 'Cache-Control'));
    }

    #[Test]
    #[Group('headers')]
    public function aWriteCarriesDeprecationButNeverCacheHeaders(): void
    {
        $response = $this->writeJsonApi('POST', '/api/relics', [
            'data' => ['type' => 'relics', 'attributes' => ['name' => 'Fresh']],
        ])->assertStatus(201);

        // A deprecated endpoint is deprecated regardless of method.
        $this->assertSame('true', $this->header($response, 'Deprecation'));

        // Caching a write is wrong: no freshness directive is emitted.
        $this->assertStringNotContainsString('max-age', $this->header($response, 'Cache-Control'));
    }

    #[Test]
    #[Group('headers')]
    public function aTypeDeclaringNoHeadersIsUntouched(): void
    {
        // `gizmos` declares no cache/deprecation config, so neither header is emitted.
        $response = $this->readJsonApi('/api/gizmos/1')->assertOk();

        $response->assertHeaderMissing('Deprecation');
        $response->assertHeaderMissing('Sunset');
        $this->assertStringNotContainsString('max-age', $this->header($response, 'Cache-Control'));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function header(TestResponse $response, string $name): string
    {
        return (string) $response->baseResponse->headers->get($name, '');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function writeJsonApi(string $method, string $uri, array $document): TestResponse
    {
        return $this->json($method, $uri, $document, [
            'Accept' => self::MEDIA_TYPE,
            'CONTENT_TYPE' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE]);
    }
}
