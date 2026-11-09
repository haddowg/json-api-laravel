<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly\RelatedOnlyServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A `relatedOnly` type serves no endpoint of its own: it renders as a related and an
 * included resource without links to routes that don't exist, can be an action's request
 * document, and the OpenAPI document gives it no paths.
 *
 * @internal
 */
#[CoversNothing]
final class RelatedOnlyTypeTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, RelatedOnlyServiceProvider::class];
    }

    #[Test]
    public function itHasNoRoutesOfItsOwn(): void
    {
        $uris = [];
        foreach ($this->resolve(Router::class)->getRoutes()->getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        self::assertContains('api/records/{id}/{relationship}', $uris);
        self::assertSame([], \array_values(\array_filter($uris, static fn(string $uri): bool => \str_starts_with($uri, 'api/imprints'))));
    }

    #[Test]
    public function anIncludedResourceRendersNoLinksToItsOwnEndpoints(): void
    {
        $document = $this->get('/api/records/r1?include=imprint')->assertOk()->json();

        self::assertSame(['self' => ['href' => 'http://localhost/api/records/r1']], self::dig($document, 'data', 'links'));
        self::assertNotNull(self::dig($document, 'data', 'relationships', 'imprint', 'links'));

        self::assertSame('imprints', self::dig($document, 'included', 0, 'type'));
        self::assertNull(self::dig($document, 'included', 0, 'links'));
        self::assertSame(['data' => ['type' => 'imprints', 'id' => 'i0']], self::dig($document, 'included', 0, 'relationships', 'parent'));
    }

    #[Test]
    public function theRelatedEndpointServesIt(): void
    {
        $document = $this->get('/api/records/r1/imprint')->assertOk()->json();

        self::assertSame('i1', self::dig($document, 'data', 'id'));
        self::assertNull(self::dig($document, 'data', 'links'));
        self::assertSame(['href' => 'http://localhost/api/records/r1/imprint'], self::dig($document, 'links', 'self'));
    }

    #[Test]
    public function anActionTakesItAsItsRequestDocument(): void
    {
        $response = $this->postJsonApi('/api/records/r1/-actions/reimprint', [
            'data' => ['type' => 'imprints', 'attributes' => ['name' => 'Fontana']],
        ]);

        $response->assertOk();
        self::assertSame('i9', self::dig($response->json(), 'data', 'relationships', 'imprint', 'data', 'id'));
    }

    #[Test]
    public function theDocumentGivesItNoPathsAndNoSelfLink(): void
    {
        $document = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        $paths = self::dig($document, 'paths');
        self::assertIsArray($paths);
        self::assertSame([], \array_values(\array_filter(\array_keys($paths), static fn(string $path): bool => \str_starts_with($path, '/imprints'))));
        self::assertArrayHasKey('/records/{id}/imprint', $paths);

        self::assertSame('#/components/schemas/ImprintsResourceLinks', self::dig($document, 'components', 'schemas', 'ImprintsResource', 'properties', 'links', '$ref'));
        self::assertNull(self::dig($document, 'components', 'schemas', 'ImprintsResourceLinks', 'properties', 'self'));
        self::assertNotNull(self::dig($document, 'components', 'schemas', 'ImprintsCreateRequest'));
        self::assertNull(self::dig($paths, '/records/{id}/imprint', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'links'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postJsonApi(string $uri, array $body): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ], \json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function dig(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
