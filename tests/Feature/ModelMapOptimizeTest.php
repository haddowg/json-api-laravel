<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\ModelMapServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The optimize half of the model-mapping tiers (ADR 0019): the `jsonapi:optimize`
 * snapshot carries the `model:` declaration (and passes servability validation, since
 * every type here resolves through a tier), so a cached (`route:cache`d) app resolves
 * the SAME `type → model` map a live scan does. The sibling
 * {@see ModelMappingTiersTest} covers the tiers over HTTP; its app also carries the
 * deliberately-unservable `ghosts` type, which would (rightly) fail this command.
 *
 * @internal
 */
#[CoversNothing]
final class ModelMapOptimizeTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    public function test_the_optimize_snapshot_carries_the_model_declaration(): void
    {
        $cacheFile = \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php';
        config(['jsonapi.discovery.cache' => $cacheFile]);

        try {
            $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
            $this->assertFileExists($cacheFile);

            // A fresh Discovery reading only the snapshot (no scan paths) yields the same
            // model declaration a live scan does, so a cached app resolves the same map.
            $cached = new Discovery(new DiscoveryScanner(), [], [], $cacheFile);
            $byType = [];
            foreach ($cached->resources() as $descriptor) {
                $byType[$descriptor->type] = $descriptor;
            }

            self::assertSame(VinylRecord::class, $byType['recordings']->model);
            // No declaration snapshots as null — the convention tier re-resolves it at
            // map time from the SAME configured namespace, cached or scanned alike.
            self::assertNull($byType['pressings']->model);
        } finally {
            if (\is_file($cacheFile)) {
                @\unlink($cacheFile);
            }
        }
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
            ModelMapServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Only the fully-servable fixture types (no Unservable dir here).
        $config->set('jsonapi.discovery.paths', [\dirname(__DIR__) . '/Fixtures/ModelMap/JsonApi']);
        $config->set('jsonapi.eloquent.model_namespace', 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('vinyl_records', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('pressings', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
    }
}
