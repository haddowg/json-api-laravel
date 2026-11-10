<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see FixedMembershipConformanceTestCase} against the **reference Eloquent
 * provider/persister pair** over real SQL (in-memory SQLite): the current members are read off
 * the `tracks` relation method the fixed views are stored as.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentFixedMembershipConformanceTest extends FixedMembershipConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return EloquentWorkbenchServiceProvider::class;
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
        (new ConformanceSeeder())->run();
    }
}
