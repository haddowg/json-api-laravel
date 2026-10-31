<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\ModelMapServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Import;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Pressing;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * `jsonapi:optimize` run again over an existing discovery snapshot rescans the app
 * instead of writing the old snapshot back, so a resource added since the last run
 * reaches the snapshot and its model map.
 *
 * @internal
 */
#[CoversNothing]
final class OptimizeRescanTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string MODEL_NAMESPACE = 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models';

    private string $cacheFile;

    private bool $withAddedResource = false;

    /**
     * @var list<string>|null
     */
    private ?array $argv = null;

    protected function setUp(): void
    {
        $this->cacheFile = \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php';

        // These boot over a snapshot that lists no resources at all.
        if (\str_contains($this->name(), 'booted_')) {
            \file_put_contents($this->cacheFile, '<?php return ' . \var_export(['resources' => []], true) . ';');
        }
        if ($this->name() === 'test_an_app_booted_to_run_optimize_ignores_the_existing_snapshot') {
            /** @var list<string> $argv */
            $argv = $_SERVER['argv'] ?? [];
            $this->argv = $argv;
            $_SERVER['argv'] = ['artisan', 'jsonapi:optimize'];
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->argv !== null) {
            $_SERVER['argv'] = $this->argv;
        }
        parent::tearDown();
        if (\is_file($this->cacheFile)) {
            @\unlink($this->cacheFile);
        }
    }

    public function test_a_second_optimize_picks_up_a_resource_added_since_the_first(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
        self::assertNotContains('sleeves', $this->snapshotTypes());

        // A new deploy: the resource exists now, and the app boots over the old snapshot.
        $this->withAddedResource = true;
        $this->refreshApplication();
        $this->defineDatabaseMigrations();

        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);

        self::assertContains('sleeves', $this->snapshotTypes());
        $snapshot = new Discovery(new DiscoveryScanner(), [], [], $this->cacheFile);
        self::assertEqualsCanonicalizing([
            'recordings' => VinylRecord::class,
            'imports' => Import::class,
            'pressings' => Pressing::class,
            'sleeves' => VinylRecord::class,
        ], $snapshot->modelMap(self::MODEL_NAMESPACE));
    }

    public function test_an_app_booted_to_run_optimize_ignores_the_existing_snapshot(): void
    {
        self::assertContains('recordings', $this->appTypes());
    }

    public function test_an_app_booted_for_anything_else_reads_the_existing_snapshot(): void
    {
        self::assertSame([], $this->appTypes());
    }

    public function test_clear_still_removes_the_snapshot(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
        self::assertFileExists($this->cacheFile);

        $this->jsonApiArtisan('jsonapi:clear')->assertExitCode(0);
        self::assertFileDoesNotExist($this->cacheFile);

        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
        self::assertFileExists($this->cacheFile);

        $this->jsonApiArtisan('optimize:clear')->assertExitCode(0);
        self::assertFileDoesNotExist($this->cacheFile);
    }

    /**
     * @return list<string>
     */
    private function appTypes(): array
    {
        return \array_map(static fn($descriptor): string => $descriptor->type, app(Discovery::class)->resources());
    }

    /**
     * @return list<string>
     */
    private function snapshotTypes(): array
    {
        $snapshot = new Discovery(new DiscoveryScanner(), [], [], $this->cacheFile);

        return \array_map(static fn($descriptor): string => $descriptor->type, $snapshot->resources());
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

        $paths = [\dirname(__DIR__) . '/Fixtures/ModelMap/JsonApi'];
        if ($this->withAddedResource) {
            $paths[] = \dirname(__DIR__) . '/Fixtures/ModelMap/Added';
        }
        $config->set('jsonapi.discovery.paths', $paths);
        $config->set('jsonapi.discovery.cache', $this->cacheFile);
        // `jsonapi:optimize` also warms the OpenAPI/JSON-Schema artifacts; point them at a
        // throwaway directory so this app's types never land in the store another test app
        // reads back.
        $config->set('jsonapi.openapi.cache_path', \sys_get_temp_dir() . '/jsonapi-artifacts-' . \uniqid());
        $config->set('jsonapi.eloquent.model_namespace', self::MODEL_NAMESPACE);
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
