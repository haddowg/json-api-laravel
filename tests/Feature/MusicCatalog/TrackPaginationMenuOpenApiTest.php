<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * Pins the OpenAPI projection of the `tracks` pagination menu: `TrackResource::pagination()`
 * is a page/cursor/offset {@see \haddowg\JsonApi\Pagination\MultiPaginator}, so the `page`
 * parameter of `/tracks` is a `oneOf` with one closed branch per strategy, each with a
 * `kind` const, discriminated on `kind`. `albums` on the same document keeps the plain page
 * object. The byte-compat diff against the Symfony bundle depends on this shape.
 *
 * @internal
 */
#[CoversNothing]
final class TrackPaginationMenuOpenApiTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    #[Test]
    #[Group('openapi')]
    public function the_tracks_collection_projects_its_pagination_menu_as_a_one_of(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        $schema = $this->pageSchema($doc, '/tracks');
        $this->assertSame(['propertyName' => 'kind'], $schema['discriminator'] ?? null);

        $shapes = [];
        foreach ($this->arrayAt($schema, 'oneOf') as $branch) {
            $this->assertFalse($this->at($branch, 'additionalProperties'));
            $properties = $this->arrayAt($branch, 'properties');
            $shapes[$this->stringAt($properties, 'kind', 'const')] = \array_keys($properties);
        }
        $this->assertSame([
            'page' => ['number', 'size', 'kind'],
            'cursor' => ['after', 'before', 'size', 'kind'],
            'offset' => ['offset', 'limit', 'kind'],
        ], $shapes);

        // The menu belongs to `tracks` alone.
        $this->assertArrayNotHasKey('oneOf', $this->pageSchema($doc, '/albums'));
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogEloquentServiceProvider::class,
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

    /**
     * The schema of the `page` deepObject parameter on `GET {$path}`.
     *
     * @param array<mixed> $doc
     *
     * @return array<mixed>
     */
    private function pageSchema(array $doc, string $path): array
    {
        foreach ($this->arrayAt($doc, 'paths', $path, 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'page') {
                return $this->arrayAt($parameter, 'schema');
            }
        }

        $this->fail("GET {$path} has no page parameter.");
    }
}
