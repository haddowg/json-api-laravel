<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see ReadConformanceTestCase} against the **reference Eloquent provider** executed
 * as real SQL over an in-memory SQLite database. It reuses the
 * {@see EloquentWorkbenchServiceProvider} (which registers the
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} at `-128`
 * over the SAME `app/JsonApi` resources) and seeds the SAME
 * {@see \Workbench\App\Support\ConformanceFixtures} rows the in-memory witness carries
 * via {@see ConformanceSeeder} — so every assertion inherited from the abstract must
 * produce the IDENTICAL result, refereeing the Eloquent SQL semantics against the
 * witness (PLAN decision 9).
 *
 * SQLite's `LOWER()`/`LIKE` fold ASCII and its BINARY text collation is byte order,
 * which is exactly the in-memory `stripos` / `<=>` contract, so the case-insensitive
 * `like` and case-sensitive byte-order sort assertions hold on both.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentReadConformanceTest extends ReadConformanceTestCase
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
