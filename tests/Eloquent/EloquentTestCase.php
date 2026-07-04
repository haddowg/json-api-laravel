<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Eloquent;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\MusicCatalogSeeder;

/**
 * The base for the Eloquent-backed suites (the feature end-to-end test and the
 * provider/handler unit tests). It boots a real Testbench Laravel app with the package
 * provider and the Eloquent workbench wiring (the {@see EloquentWorkbenchServiceProvider}
 * registers the reference {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider}
 * at `-128` and discovers the SAME `app/JsonApi` resources the in-memory suite uses),
 * against an in-memory SQLite database migrated from the workbench schema.
 *
 * SQLite's `LOWER()`/`LIKE` fold ASCII, which is exactly the `like` contract, so the
 * case-insensitivity parity holds against the in-memory witness. A fresh `:memory:`
 * database is migrated per test, so each test seeds only what it needs — the canonical
 * {@see Fixtures} via {@see seedFixtures()}, or its own richer rows.
 *
 * @internal
 */
abstract class EloquentTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            EloquentWorkbenchServiceProvider::class,
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
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    /**
     * Seeds the canonical music-catalog rows (identical to the in-memory suite's
     * {@see \Workbench\App\Providers\WorkbenchServiceProvider} seed) via the shared
     * {@see MusicCatalogSeeder}, so a dual-provider assertion compares like data.
     */
    protected function seedFixtures(): void
    {
        (new MusicCatalogSeeder())->run();
    }
}
