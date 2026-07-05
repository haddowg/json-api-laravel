<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * Pins the standalone-serializer types (charts + countries) into the two sibling paths of
 * the OpenAPI document (PLAN decision 3, bundle ADR 0024): the standalone JSON Schema
 * export ({@see JsonSchemaFactory} — `jsonapi:jsonschema:export` / `/schemas.json`) and
 * the servability warmer ({@see ServableResourceWarmer} — `jsonapi:optimize`). Both
 * previously enumerated the resource channel only, so a serializer-only type appeared in
 * the OpenAPI document but was silently absent from schemas.json and unvalidated at
 * deploy. The shape assertions mirror {@see StandaloneSerializerOpenApiTest}: a fieldless
 * type projects a permissive inline `attributes: {type: object}` — the same
 * `SchemaProjector` projection the OpenAPI document carries.
 *
 * @internal
 */
#[CoversNothing]
final class StandaloneSerializerJsonSchemaTest extends Orchestra
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
     * The standalone schema for `$type`, decoded to an `array<string, mixed>` tree.
     *
     * @return array<string, mixed>
     */
    private function schema(string $type): array
    {
        $decoded = \json_decode((string) \json_encode($this->resolve(JsonSchemaFactory::class)->forType($type)), true);
        $this->assertIsArray($decoded);
        \assert(\array_is_list($decoded) === false);

        return $decoded;
    }

    #[Test]
    #[Group('openapi')]
    public function the_export_includes_the_standalone_types_alongside_the_resources(): void
    {
        $documents = $this->resolve(JsonSchemaFactory::class)->forServer('default');

        // The resource channel still projects…
        $this->assertArrayHasKey('albums', $documents);
        // …and the serializer channel now does too — the same type set the OpenAPI
        // document enumerates.
        $this->assertArrayHasKey('charts', $documents);
        $this->assertArrayHasKey('countries', $documents);
    }

    #[Test]
    #[Group('openapi')]
    public function a_standalone_type_projects_a_fieldless_document_with_the_dialect_and_id(): void
    {
        foreach (['charts', 'countries'] as $type) {
            $schema = $this->schema($type);

            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $this->at($schema, '$schema'));
            $this->assertSame('urn:jsonapi:schema:' . $type, $this->at($schema, '$id'));
            $this->assertSame($type, $this->at($schema, 'properties', 'type', 'const'));

            // The crux (mirroring the OpenAPI projection): a resource-less type has no
            // field inventory, so its attributes object is inline and permissive —
            // exactly `{"type": "object"}`.
            $this->assertSame(['type' => 'object'], $this->arrayAt($schema, 'properties', 'attributes'));
        }
    }

    #[Test]
    #[Group('openapi')]
    public function the_combined_aggregate_carries_the_standalone_types(): void
    {
        $documents = $this->resolve(JsonSchemaFactory::class)->combined();

        $this->assertArrayHasKey('charts', $documents);
        $this->assertArrayHasKey('countries', $documents);
    }

    #[Test]
    #[Group('openapi')]
    public function the_servable_warmer_validates_the_standalone_types_without_problems(): void
    {
        // The warmer now walks the serializer channel too: charts/countries expose fetch
        // operations and their custom providers support them, so the standalone types
        // contribute no problem. (Assertions are scoped to the standalone types — the
        // failure path lives in StandaloneSerializerServabilityTest.)
        $joined = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        $this->assertStringNotContainsString('charts', $joined);
        $this->assertStringNotContainsString('countries', $joined);
    }
}
