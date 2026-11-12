<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\SortExample\SortExampleServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * The type and relation metadata report what the `sort` example and the filter defaults
 * are built from: a resource's `defaultSort()` and `sortExample()`, and the defaults a
 * relation drops with `withoutFilterDefaults()`.
 *
 * @internal
 */
#[Group('openapi')]
final class OpenApiSortExampleAndFilterDefaultsTest extends Orchestra
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
            MusicCatalogInMemoryServiceProvider::class,
            SortExampleServiceProvider::class,
        ];
    }

    #[Test]
    public function theSortExampleIsTheTypesDefaultOrder(): void
    {
        self::assertSame(['name'], $this->parameter('/gadgets', 'sort')['example'] ?? null);
    }

    #[Test]
    public function aDeclaredSortExampleReplacesTheDefaultOrder(): void
    {
        // Albums default to `-releasedAt` and declare `-releasedAt,title`, which a relation
        // to albums with no order of its own shows too.
        self::assertSame(['-releasedAt', 'title'], $this->parameter('/albums', 'sort')['example'] ?? null);
        self::assertSame(['-releasedAt', 'title'], $this->parameter('/artists/{id}/albums', 'sort')['example'] ?? null);
    }

    #[Test]
    public function aRelationsEndpointsLeaveOffTheDefaultItDrops(): void
    {
        // `tracks` keeps the tracks type's `explicit=false` default; `tracklist` drops it.
        self::assertFalse($this->arrayAt($this->parameter('/albums/{id}/tracks', 'filter[explicit]'), 'schema')['default'] ?? null);
        self::assertArrayNotHasKey('default', $this->arrayAt($this->parameter('/albums/{id}/tracklist', 'filter[explicit]'), 'schema'));
        self::assertArrayNotHasKey('default', $this->arrayAt($this->parameter('/albums/{id}/relationships/tracklist', 'filter[explicit]'), 'schema'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parameter(string $path, string $name): array
    {
        $document = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        foreach ($this->arrayAt($document, 'paths', $path, 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail(\sprintf('%s has no %s parameter.', $path, $name));
    }
}
