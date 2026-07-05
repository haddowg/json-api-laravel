<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the `optimizes()` pipeline (PLAN decision 11): `jsonapi:optimize` validates
 * servability then warms the OpenAPI + JSON Schema artifacts into the {@see ArtifactStore}
 * (which the controllers then serve `O(file read)`), and `jsonapi:clear` removes them.
 *
 * The fully-servable workbench surface warms cleanly; the servability guard's failure
 * path (a routed type with no provider) is exercised by {@see OpenApiServabilityTest}.
 *
 * @internal
 */
final class OpenApiOptimizeTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-optimize-' . \uniqid();
        config(['jsonapi.openapi.cache_path' => $this->cacheDir]);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->cacheDir)) {
            $this->resolve(ArtifactStore::class)->clear();
            @\rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    #[Test]
    #[Group('openapi')]
    public function the_workbench_surface_is_fully_servable(): void
    {
        $this->assertSame([], $this->resolve(ServableResourceWarmer::class)->warm());
    }

    #[Test]
    #[Group('openapi')]
    public function optimize_warms_the_artifacts(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);

        $store = $this->resolve(ArtifactStore::class);

        $document = $store->read('default');
        $this->assertIsString($document);
        $decoded = \json_decode($document, true);
        $this->assertIsArray($decoded);
        $this->assertStringStartsWith('3.1', $this->stringAt($decoded, 'openapi'));

        $aggregate = $store->readSchemaAggregate('default');
        $this->assertIsString($aggregate);
        $decodedAggregate = \json_decode($aggregate, true);
        $this->assertIsArray($decodedAggregate);
        $this->assertArrayHasKey('albums', $decodedAggregate);
    }

    #[Test]
    #[Group('openapi')]
    public function clear_removes_the_warmed_artifacts(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);

        $store = $this->resolve(ArtifactStore::class);
        $this->assertIsString($store->read('default'));

        $this->jsonApiArtisan('jsonapi:clear')->assertExitCode(0);

        $this->assertNull($store->read('default'));
        $this->assertNull($store->readSchemaAggregate('default'));
    }

    #[Test]
    #[Group('openapi')]
    public function it_registers_the_optimize_pipeline_hooks(): void
    {
        // The provider wires `jsonapi:optimize` / `jsonapi:clear` into `artisan optimize`
        // / `optimize:clear` via the framework's optimize command registry.
        $this->assertContains('jsonapi:optimize', ServiceProvider::$optimizeCommands);
        $this->assertContains('jsonapi:clear', ServiceProvider::$optimizeClearCommands);
    }
}
