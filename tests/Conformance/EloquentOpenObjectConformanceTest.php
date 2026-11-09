<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Sheet;
use Workbench\App\Providers\OpenObjectEloquentServiceProvider;
use Workbench\App\Validation\Sheet as SheetSeed;

/**
 * The open-object conformance cases over the Eloquent provider, each object a real
 * `json` column in in-memory SQLite.
 */
#[CoversNothing]
final class EloquentOpenObjectConformanceTest extends OpenObjectConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return OpenObjectEloquentServiceProvider::class;
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
        Sheet::query()->create([
            'id' => 1,
            'name' => 'Seed',
            'rows' => SheetSeed::SEED_ROWS,
            'labels' => SheetSeed::SEED_LABELS,
            'settings' => SheetSeed::SEED_SETTINGS,
            'metrics' => SheetSeed::SEED_METRICS,
        ]);
    }
}
