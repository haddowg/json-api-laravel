<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * The Eloquent arm of the music-catalog smoke suite: the full domain served over the
 * reference {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} against
 * a migrated + seeded in-memory SQLite database.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentMusicCatalogSmokeTest extends MusicCatalogSmokeTestCase
{
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
        parent::defineEnvironment($app);

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
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new McCatalogSeeder())->run();
    }
}
