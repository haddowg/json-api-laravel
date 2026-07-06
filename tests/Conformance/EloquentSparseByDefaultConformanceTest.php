<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\SparseWidget;
use Workbench\App\Providers\SparseEloquentServiceProvider;

/**
 * {@see SparseByDefaultConformanceTestCase} against the reference Eloquent provider: the
 * sparse-by-default `expensiveScore` attribute is read off a real `expensive_score`
 * column on {@see SparseWidget} in an in-memory SQLite database — so the same assertions
 * witness the opt-in visibility tier over column storage, not just an in-memory array.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentSparseByDefaultConformanceTest extends SparseByDefaultConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SparseEloquentServiceProvider::class;
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
        SparseWidget::query()->create([
            'id' => 1,
            'name' => 'Gadget',
            'expensive_score' => 99,
        ]);
    }
}
