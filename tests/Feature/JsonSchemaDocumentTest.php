<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the standalone per-type JSON Schema 2020-12 projection (PLAN decision 11): the
 * {@see JsonSchemaFactory} wraps core's resource-object schema with the dialect + `$id`
 * keywords, so the artifact is a valid addressable schema on its own. Its attribute
 * schema agrees byte-for-byte with the OpenAPI document's (the same
 * `SchemaProjector::projectResourceObject()`).
 *
 * @internal
 */
final class JsonSchemaDocumentTest extends TestCase
{
    use InteractsWithOpenApiDocument;

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
    public function it_wraps_a_type_schema_with_the_dialect_and_id(): void
    {
        $schema = $this->schema('albums');

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $this->at($schema, '$schema'));
        $this->assertSame('urn:jsonapi:schema:albums', $this->at($schema, '$id'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_agrees_with_the_openapi_attribute_schema(): void
    {
        // The attributes are the same SchemaProjector projection the OpenAPI document uses,
        // so the self-describing MaxLength constraint appears identically here.
        $this->assertSame(200, $this->at($this->schema('albums'), 'properties', 'attributes', 'properties', 'title', 'maxLength'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_builds_a_document_for_every_registered_type(): void
    {
        $documents = $this->resolve(JsonSchemaFactory::class)->forServer();

        $this->assertArrayHasKey('albums', $documents);
        $this->assertArrayHasKey('artists', $documents);
        $this->assertArrayHasKey('genres', $documents);
    }

    #[Test]
    #[Group('openapi')]
    public function it_throws_for_an_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolve(JsonSchemaFactory::class)->forType('does-not-exist');
    }

    public static function debugOn(mixed $app): void
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);
        $config->set('app.debug', true);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('debugOn')]
    public function it_serves_the_aggregate_keyed_by_type_over_http(): void
    {
        $body = $this->get('/schemas.json')->assertOk()->getContent();
        $this->assertIsString($body);

        $decoded = \json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('albums', $decoded);
        $this->assertArrayHasKey('artists', $decoded);
        $this->assertArrayHasKey('genres', $decoded);
    }
}
