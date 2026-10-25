<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Eloquent\ModelMapResolver;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * One type declared against two different models, here by exposing `recordings` on a
 * second server through a class that declares another model, fails both when the map
 * is resolved live and when `jsonapi:optimize` writes it into the snapshot (ADR 0053).
 *
 * @internal
 */
#[CoversNothing]
final class ModelMapConflictTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (\is_file($this->cacheFile)) {
            @\unlink($this->cacheFile);
        }
        parent::tearDown();
    }

    public function test_the_live_map_rejects_a_type_declared_against_two_models(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('JSON:API type "recordings" is mapped to two different Eloquent models');

        app(ModelMapResolver::class)->map();
    }

    public function test_optimize_rejects_a_type_declared_against_two_models(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('JSON:API type "recordings" is mapped to two different Eloquent models');

        try {
            $this->jsonApiArtisan('jsonapi:optimize')->run();
        } finally {
            self::assertFileDoesNotExist($this->cacheFile);
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'archive' => ['prefix' => 'archive-api', 'middleware' => [], 'domain' => null],
        ]);
        $config->set('jsonapi.discovery.paths', [
            \dirname(__DIR__) . '/Fixtures/ModelMap/JsonApi',
            \dirname(__DIR__) . '/Fixtures/ModelMap/Conflict',
        ]);
        $config->set('jsonapi.discovery.cache', $this->cacheFile);
        $config->set('jsonapi.openapi.cache_path', \sys_get_temp_dir() . '/jsonapi-artifacts-' . \uniqid());
        $config->set('jsonapi.eloquent.model_namespace', 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models');
    }
}
