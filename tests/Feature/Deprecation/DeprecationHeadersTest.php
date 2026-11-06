<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Deprecation;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation\DeprecationServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A response to an operation or action deprecated on its own carries `Deprecation: true`,
 * and a response to its current siblings does not.
 *
 * @internal
 */
final class DeprecationHeadersTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, DeprecationServiceProvider::class];
    }

    #[Test]
    public function aDeprecatedOperationCarriesTheHeader(): void
    {
        $response = $this->invoke('/api/articles/2', 'DELETE');

        $response->assertNoContent();
        self::assertSame('true', $response->headers->get('Deprecation'));
        $response->assertHeaderMissing('Sunset');
    }

    #[Test]
    public function aDeprecatedRelatedEndpointCarriesTheHeader(): void
    {
        $response = $this->read('/api/articles/1/writer');

        self::assertSame('true', $response->headers->get('Deprecation'));
    }

    #[Test]
    public function theSiblingOperationsDoNot(): void
    {
        foreach (['/api/articles', '/api/articles/1', '/api/articles/1/relationships/writer'] as $uri) {
            $this->read($uri)->assertHeaderMissing('Deprecation');
        }
    }

    #[Test]
    public function aDeprecatedActionCarriesTheHeaderAndItsSiblingDoesNot(): void
    {
        $publish = $this->invoke('/api/articles/1/-actions/publish')->assertOk();
        self::assertSame('true', $publish->headers->get('Deprecation'));

        $this->invoke('/api/articles/1/-actions/share')
            ->assertOk()
            ->assertHeaderMissing('Deprecation');
    }

    #[Test]
    public function aDeprecatedTypeKeepsItsDeclaredHeaders(): void
    {
        $response = $this->read('/api/vaults/1');

        self::assertSame('Mon, 01 Jun 2026 00:00:00 GMT', $response->headers->get('Deprecation'));
        self::assertSame('Sat, 01 Jan 2028 00:00:00 GMT', $response->headers->get('Sunset'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function invoke(string $uri, string $method = 'POST'): TestResponse
    {
        return $this->call($method, $uri, server: ['HTTP_ACCEPT' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri): TestResponse
    {
        return $this->get($uri, ['Accept' => self::MEDIA_TYPE])->assertOk();
    }
}
