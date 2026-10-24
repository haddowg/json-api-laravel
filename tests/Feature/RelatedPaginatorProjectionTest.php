<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedPagination\RelatedPaginationServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A to-many relation that declares no paginator is served with its related resource's own
 * paginator, and the document must describe that one rather than the server default.
 *
 * @internal
 */
#[CoversNothing]
final class RelatedPaginatorProjectionTest extends Orchestra
{
    use InteractsWithOpenApiDocument;
    use SchemaConformanceTrait;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            RelatedPaginationServiceProvider::class,
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relationEndpoints(): iterable
    {
        yield 'related' => ['/shelves/{id}/books'];
        yield 'relationship' => ['/shelves/{id}/relationships/books'];
    }

    #[DataProvider('relationEndpoints')]
    public function test_the_page_schema_states_the_related_resources_numbers(string $path): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();

        $size = $this->pageProperty($doc, $path, 'size');
        self::assertSame(2, $size['default'] ?? null);
        self::assertSame(3, $size['maximum'] ?? null);

        // The primary collection carries the same numbers, from the same resource.
        self::assertSame($size, $this->pageProperty($doc, '/books', 'size'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function servedEndpoints(): iterable
    {
        yield 'related' => ['/api/shelves/1/books'];
        yield 'relationship' => ['/api/shelves/1/relationships/books'];
    }

    #[DataProvider('servedEndpoints')]
    public function test_the_endpoint_serves_the_related_resources_page_size(string $uri): void
    {
        $default = $this->get($uri, ['Accept' => 'application/vnd.api+json']);
        $default->assertOk();
        self::assertCount(2, (array) $default->json('data'));

        $capped = $this->get($uri . '?page[size]=50', ['Accept' => 'application/vnd.api+json']);
        $capped->assertOk();
        self::assertCount(3, (array) $capped->json('data'));
    }

    public function test_a_plain_relationship_read_serves_the_documented_default_page(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        $size = $this->pageProperty($doc, '/shelves/{id}/relationships/books', 'size');

        $response = $this->get('/api/shelves/1/relationships/books', ['Accept' => 'application/vnd.api+json']);
        $response->assertOk();

        self::assertIsInt($size['default'] ?? null);
        self::assertCount($size['default'], (array) $response->json('data'));
        self::assertStringContainsString('page%5Bnumber%5D=1', $this->linkHref($response, 'first'));
        self::assertStringContainsString('page%5Bnumber%5D=2', $this->linkHref($response, 'next'));
        self::assertNull($response->json('links.prev'));

        $this->assertResponseMatchesGeneratedSchema($response, 'shelves', SchemaDocumentKind::Relationship, 'books');
    }

    public function test_an_unpaginated_relation_still_serves_its_whole_linkage(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer()->toArray();
        $operation = $this->arrayAt($doc, 'paths', '/shelves/{id}/relationships/everyBook', 'get');
        self::assertNotContains('page', \array_column((array) ($operation['parameters'] ?? []), 'name'));

        $response = $this->get('/api/shelves/1/relationships/everyBook', ['Accept' => 'application/vnd.api+json']);
        $response->assertOk();

        self::assertSame(['1', '2', '3', '4', '5'], \array_column((array) $response->json('data'), 'id'));
        self::assertNull($response->json('links.first'));
        self::assertNull($response->json('links.next'));
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<array-key, mixed>
     */
    private function pageProperty(array $doc, string $path, string $property): array
    {
        foreach ($this->arrayAt($doc, 'paths', $path, 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'page') {
                return $this->arrayAt($parameter, 'schema', 'properties', $property);
            }
        }

        self::fail(\sprintf('The `page` parameter was not found on GET %s.', $path));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function linkHref(TestResponse $response, string $name): string
    {
        $href = $response->json('links.' . $name . '.href');
        self::assertIsString($href);

        return $href;
    }
}
