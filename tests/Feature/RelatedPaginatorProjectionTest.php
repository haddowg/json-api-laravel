<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedPagination\RelatedPaginationServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
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
     * The relationship endpoint pages only when the request carries a query, so its
     * default-size request names the first page.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function servedEndpoints(): iterable
    {
        yield 'related' => ['/api/shelves/1/books', ''];
        yield 'relationship' => ['/api/shelves/1/relationships/books', '?page[number]=1'];
    }

    #[DataProvider('servedEndpoints')]
    public function test_the_endpoint_serves_the_related_resources_page_size(string $uri, string $firstPage): void
    {
        $default = $this->get($uri . $firstPage, ['Accept' => 'application/vnd.api+json']);
        $default->assertOk();
        self::assertCount(2, (array) $default->json('data'));

        $capped = $this->get($uri . '?page[size]=50', ['Accept' => 'application/vnd.api+json']);
        $capped->assertOk();
        self::assertCount(3, (array) $capped->json('data'));
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
}
