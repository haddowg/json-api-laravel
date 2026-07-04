<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\SecurityEloquentServiceProvider;
use Workbench\Database\Seeders\MusicCatalogSeeder;

/**
 * {@see SecurityConformanceTestCase} against the **reference Eloquent provider/persister
 * pair** over an in-memory SQLite database. The SAME dedicated `AlbumApiPolicy` (mapped
 * onto the resource, not the application Gate) authorizes the Eloquent model, refereeing
 * that the policy-first authorization holds identically against real SQL and the
 * in-memory witness.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentSecurityConformanceTest extends SecurityConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SecurityEloquentServiceProvider::class;
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        (new MusicCatalogSeeder())->run();
    }
}
