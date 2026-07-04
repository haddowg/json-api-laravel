<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\CursorConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * {@see CursorConformanceTestCase} against the **reference Eloquent provider** executed
 * as real SQL over in-memory SQLite. It reuses the
 * {@see CursorConformanceEloquentServiceProvider} (registering the
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} at `-128`
 * over the isolated cursorWidgets resource) and seeds the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures::cursorWidgets()} rows the in-memory
 * witness carries via {@see CursorWidgetSeeder} — so every inherited assertion must
 * produce the IDENTICAL keyset page, refereeing the SQL push-down against the witness
 * (PLAN decision 9, bundle ADR 0063).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentCursorConformanceTest extends CursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return CursorConformanceEloquentServiceProvider::class;
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

    protected function seedConformanceData(): void
    {
        (new CursorWidgetSeeder())->run();
    }
}
