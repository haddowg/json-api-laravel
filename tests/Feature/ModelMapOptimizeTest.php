<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Eloquent\ModelMapResolver;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\ModelMapServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Import;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\Pressing;
use haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models\VinylRecord;
use haddowg\JsonApiLaravel\Tests\Support\InflectionSpy;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Depends;

/**
 * The optimize half of the model-mapping tiers: the `jsonapi:optimize`
 * snapshot carries the `model:` declaration (and passes servability validation, since
 * every type here resolves through a tier), so a cached (`route:cache`d) app resolves
 * the SAME `type → model` map a live scan does. The sibling
 * {@see ModelMappingTiersTest} covers the tiers over HTTP; its app also carries the
 * deliberately-unservable `ghosts` type, which would (rightly) fail this command.
 *
 * The snapshot also carries the resolved map itself, so an app booted from it serves the
 * Eloquent and in-memory types without inflecting. Those tests boot from the
 * snapshot the first test writes, so they depend on it.
 *
 * @internal
 */
#[CoversNothing]
final class ModelMapOptimizeTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string MODEL_NAMESPACE = 'haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models';

    private const array EXPECTED_MAP = [
        'recordings' => VinylRecord::class,
        'imports' => Import::class,
        'pressings' => Pressing::class,
    ];

    private static ?string $optimized = null;

    private ?string $cacheFile = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$optimized !== null && \is_file(self::$optimized)) {
            @\unlink(self::$optimized);
        }
        self::$optimized = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        // The app reads the snapshot while it boots, so it has to be on disk before then.
        $this->cacheFile = match ($this->name()) {
            'test_an_app_booted_from_the_snapshot_serves_its_types_without_inflecting' => $this->copyOfOptimized(static fn(array $data): array => $data),
            'test_a_snapshot_without_the_model_map_still_resolves_it' => $this->copyOfOptimized(static function (array $data): array {
                unset($data['models']);

                return $data;
            }),
            'test_a_snapshot_resolved_under_another_namespace_is_resolved_again' => $this->copyOfOptimized(static function (array $data): array {
                \assert(\is_array($data['models']));
                $data['models']['namespace'] = 'App\\Models';
                $data['models']['map'] = [];

                return $data;
            }),
            'test_the_live_scan_resolves_the_same_map' => null,
            default => \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php',
        };

        parent::setUp();
    }

    protected function tearDown(): void
    {
        InflectionSpy::uninstall();
        if ($this->cacheFile !== null && $this->cacheFile !== self::$optimized && \is_file($this->cacheFile)) {
            @\unlink($this->cacheFile);
        }
        parent::tearDown();
    }

    public function test_the_optimize_snapshot_carries_the_model_declaration(): void
    {
        \assert($this->cacheFile !== null);

        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
        $this->assertFileExists($this->cacheFile);

        // A fresh Discovery reading only the snapshot (no scan paths) yields the same
        // model declaration a live scan does, so a cached app resolves the same map.
        $cached = new Discovery(new DiscoveryScanner(), [], [], $this->cacheFile);
        $byType = [];
        foreach ($cached->resources() as $descriptor) {
            $byType[$descriptor->type] = $descriptor;
        }

        self::assertSame(VinylRecord::class, $byType['recordings']->model);
        // No declaration snapshots as null on the descriptor; the resolved map below
        // carries the convention guess.
        self::assertNull($byType['pressings']->model);

        self::assertEqualsCanonicalizing(self::EXPECTED_MAP, $cached->modelMap(self::MODEL_NAMESPACE));

        self::$optimized = $this->cacheFile;
    }

    #[Depends('test_the_optimize_snapshot_carries_the_model_declaration')]
    public function test_an_app_booted_from_the_snapshot_serves_its_types_without_inflecting(): void
    {
        InflectionSpy::install();
        VinylRecord::query()->create(['title' => 'Loveless']);
        Pressing::query()->create(['title' => 'First pressing']);

        self::assertEqualsCanonicalizing(self::EXPECTED_MAP, app(ModelMapResolver::class)->map());

        // Both providers: the auto-registered Eloquent pair and the explicit in-memory one.
        $this->get('/api/recordings', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonPath('data.0.attributes.title', 'Loveless');
        $this->get('/api/pressings/1', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonPath('data.attributes.title', 'First pressing');
        $this->get('/api/imports/1', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonPath('data.attributes.title', ModelMapServiceProvider::SHADOW_TITLE);

        self::assertSame(0, InflectionSpy::$singularized);
    }

    #[Depends('test_the_optimize_snapshot_carries_the_model_declaration')]
    public function test_a_snapshot_without_the_model_map_still_resolves_it(): void
    {
        InflectionSpy::install();
        Pressing::query()->create(['title' => 'First pressing']);

        self::assertEqualsCanonicalizing(self::EXPECTED_MAP, app(ModelMapResolver::class)->map());
        self::assertGreaterThan(0, InflectionSpy::$singularized);

        $this->get('/api/pressings/1', ['Accept' => self::MEDIA_TYPE])->assertOk();
        $this->get('/api/imports/1', ['Accept' => self::MEDIA_TYPE])->assertOk();
    }

    #[Depends('test_the_optimize_snapshot_carries_the_model_declaration')]
    public function test_a_snapshot_resolved_under_another_namespace_is_resolved_again(): void
    {
        self::assertEqualsCanonicalizing(self::EXPECTED_MAP, app(ModelMapResolver::class)->map());
    }

    public function test_the_live_scan_resolves_the_same_map(): void
    {
        InflectionSpy::install();

        self::assertEqualsCanonicalizing(self::EXPECTED_MAP, app(ModelMapResolver::class)->map());
        self::assertGreaterThan(0, InflectionSpy::$singularized);
    }

    /**
     * @param \Closure(array<mixed>): array<mixed> $edit
     */
    private function copyOfOptimized(\Closure $edit): string
    {
        if (self::$optimized === null || !\is_file(self::$optimized)) {
            self::markTestSkipped('The optimize test did not write a snapshot.');
        }

        /** @var array<mixed> $data */
        $data = require self::$optimized;
        $file = \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php';
        \file_put_contents($file, '<?php return ' . \var_export($edit($data), true) . ';');

        return $file;
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

        if ($this->cacheFile !== null) {
            $config->set('jsonapi.discovery.cache', $this->cacheFile);
        }
        // `jsonapi:optimize` also warms the OpenAPI/JSON-Schema artifacts; point them at a
        // throwaway directory so this app's types never land in the store another test app
        // reads back.
        $config->set('jsonapi.openapi.cache_path', \sys_get_temp_dir() . '/jsonapi-artifacts-' . \uniqid());

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
