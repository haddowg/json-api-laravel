<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\MusicCatalogSeeder;

/**
 * {@see WriteConformanceTestCase} against the **reference Eloquent provider/persister
 * pair** executed as real SQL over an in-memory SQLite database. The
 * {@see EloquentWorkbenchServiceProvider} registers the pair at `-128` over the SAME
 * `app/JsonApi` resources; the minimal {@see \Workbench\App\Support\Fixtures} are seeded
 * via {@see MusicCatalogSeeder} — the SAME two albums the in-memory witness carries — so
 * a created row is the predictable auto-increment id `3` on both, refereeing the Eloquent
 * write + transaction semantics against the witness (PLAN decision 9).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentWriteConformanceTest extends WriteConformanceTestCase
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
        (new MusicCatalogSeeder())->run();
    }
}
