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
 * Pins the fieldless OpenAPI projection of the standalone-serializer types
 * (charts + countries) — the resource-less capability (PLAN decision 3, bundle ADR 0024).
 * The byte-for-byte diff against the bundle lives in `composer byte-compat`; this focused
 * suite asserts the shape the diff depends on: the two types are projected (paths +
 * component schemas), their operation allow-list is read-only, and — the crux — a
 * fieldless type's resource-object schema carries an **inline** `attributes: {type: object}`
 * with **no** `{Type}Attributes` `$ref`, because a resource-less type declares no field
 * inventory.
 *
 * @internal
 */
#[CoversNothing]
final class StandaloneSerializerOpenApiTest extends Orchestra
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
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        \assert(\array_is_list($doc) === false);

        return $doc;
    }

    #[Test]
    #[Group('openapi')]
    public function it_projects_the_standalone_types_as_read_only_paths(): void
    {
        $doc = $this->document();

        foreach (['/charts', '/countries'] as $collection) {
            $paths = $this->arrayAt($doc, 'paths', $collection);
            $this->assertArrayHasKey('get', $paths);
            // Serialize-plus-fetch only: the allow-list opened neither Create nor the
            // resource write verbs, so no write operation is documented.
            $this->assertArrayNotHasKey('post', $paths);

            $resource = $this->arrayAt($doc, 'paths', $collection . '/{id}');
            $this->assertArrayHasKey('get', $resource);
            $this->assertArrayNotHasKey('patch', $resource);
            $this->assertArrayNotHasKey('delete', $resource);
        }
    }

    #[Test]
    #[Group('openapi')]
    public function a_fieldless_type_projects_inline_attributes_with_no_attributes_ref(): void
    {
        $doc = $this->document();

        foreach (['Charts', 'Countries'] as $type) {
            $attributes = $this->arrayAt($doc, 'components', 'schemas', $type . 'Resource', 'properties', 'attributes');

            // The crux: a resource-less type has no field inventory, so its attributes object
            // is inline and permissive — exactly `{"type": "object"}`, never a $ref to a
            // {Type}Attributes component.
            $this->assertSame(['type' => 'object'], $attributes);
            $this->assertArrayNotHasKey('$ref', $attributes);

            // And no per-type attributes component is emitted for a fieldless type.
            $schemas = $this->arrayAt($doc, 'components', 'schemas');
            $this->assertArrayNotHasKey($type . 'Attributes', $schemas);
        }
    }
}
