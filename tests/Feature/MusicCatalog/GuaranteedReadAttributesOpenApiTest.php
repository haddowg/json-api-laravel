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
 * The catalogue's server-filled read-only attributes are always set, so they are marked
 * `guaranteed()` and the read schema types them without `null`. An optional attribute the
 * server may leave unset keeps admitting `null`.
 *
 * @internal
 */
#[CoversNothing]
final class GuaranteedReadAttributesOpenApiTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    #[Test]
    #[Group('openapi')]
    public function server_filled_read_only_attributes_are_typed_non_null(): void
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();

        $this->assertSame('string', $this->attributeType($doc, 'PlaylistsAttributes', 'slug'));
        $this->assertSame('string', $this->attributeType($doc, 'CatalogExportsAttributes', 'status'));
        $this->assertSame('string', $this->attributeType($doc, 'ExportJobsAttributes', 'state'));

        $this->assertSame(['string', 'null'], $this->attributeType($doc, 'AlbumsAttributes', 'artwork'));
    }

    /**
     * @param array<mixed> $doc
     */
    private function attributeType(array $doc, string $schema, string $attribute): mixed
    {
        $node = $doc;
        foreach (['components', 'schemas', $schema, 'properties', $attribute] as $key) {
            $this->assertIsArray($node);
            $this->assertArrayHasKey($key, $node);
            $node = $node[$key];
        }
        $this->assertIsArray($node);

        return $node['type'] ?? null;
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
}
