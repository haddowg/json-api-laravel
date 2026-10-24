<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\ComponentPruner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ComponentPrunerTest extends TestCase
{
    #[Test]
    public function it_drops_what_removed_operations_alone_reached_transitively(): void
    {
        $warmed = $this->document();
        $baseline = ComponentPruner::baseline($warmed);

        $served = $this->document();
        unset($served->paths->{'/b'}->get);

        $pruned = ComponentPruner::prune($served, $baseline);

        $this->assertObjectNotHasProperty('/b', $pruned->paths);
        $schemas = \array_keys(\get_object_vars($pruned->components->schemas));
        \sort($schemas);
        // B and its discriminator target BChild go; Shared stays because A still uses it;
        // Unused was never referenced, so it stays and keeps what it references.
        $this->assertSame(['A', 'Shared', 'Unused', 'UsedByUnused'], $schemas);
        $this->assertObjectNotHasProperty('responses', $pruned->components);
        $this->assertObjectHasProperty('securitySchemes', $pruned->components);
        $this->assertSame(['a', 'configured'], \array_map(static fn(\stdClass $tag): string => $tag->name, $pruned->tags));
    }

    #[Test]
    public function a_component_a_decorator_adds_is_a_root(): void
    {
        $baseline = ComponentPruner::baseline($this->document());

        $served = $this->document();
        unset($served->paths->{'/b'}->get);
        $served->components->schemas->Added = (object) ['$ref' => '#/components/schemas/B'];

        $pruned = ComponentPruner::prune($served, $baseline);

        $this->assertObjectHasProperty('Added', $pruned->components->schemas);
        $this->assertObjectHasProperty('B', $pruned->components->schemas);
        $this->assertObjectHasProperty('BChild', $pruned->components->schemas);
    }

    #[Test]
    public function an_undecorated_document_is_left_as_it_was(): void
    {
        $baseline = ComponentPruner::baseline($this->document());

        $this->assertEquals($this->document(), ComponentPruner::prune($this->document(), $baseline));
    }

    private function document(): \stdClass
    {
        $json = <<<'JSON'
            {
              "openapi": "3.1.0",
              "tags": [{"name": "a"}, {"name": "b"}, {"name": "configured"}],
              "paths": {
                "/a": {"get": {"tags": ["a"], "responses": {"200": {"content": {"application/json": {"schema": {"$ref": "#/components/schemas/A"}}}}}}},
                "/b": {"get": {"tags": ["b"], "responses": {"200": {"$ref": "#/components/responses/BResponse"}}}}
              },
              "components": {
                "schemas": {
                  "A": {"properties": {"shared": {"$ref": "#/components/schemas/Shared"}}},
                  "B": {"oneOf": [{"$ref": "#/components/schemas/Shared"}], "discriminator": {"propertyName": "t", "mapping": {"child": "#/components/schemas/BChild"}}},
                  "BChild": {"type": "object"},
                  "Shared": {"type": "string"},
                  "Unused": {"properties": {"x": {"$ref": "#/components/schemas/UsedByUnused"}}},
                  "UsedByUnused": {"type": "integer"}
                },
                "responses": {
                  "BResponse": {"description": "B", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/B"}}}}
                },
                "securitySchemes": {"bearer": {"type": "http", "scheme": "bearer"}}
              }
            }
            JSON;

        $decoded = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $decoded);

        return $decoded;
    }
}
