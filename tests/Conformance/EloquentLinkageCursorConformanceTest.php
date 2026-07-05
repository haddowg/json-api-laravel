<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelatedCursorConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\CursorGroupSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * {@see LinkageCursorConformanceTestCase} against the **reference Eloquent provider**
 * executed as real SQL over in-memory SQLite: a queried linkage read reuses the SAME
 * `cursorGroups.widgets` parent-scoped keyset push-down the related endpoint runs
 * (the identical {@see RelatedCursorConformanceEloquentServiceProvider} wiring), so
 * every inherited assertion must produce the IDENTICAL identifier page the witness
 * renders (docs/adr/0017, core ADR 0124).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentLinkageCursorConformanceTest extends LinkageCursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelatedCursorConformanceEloquentServiceProvider::class;
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
        (new CursorWidgetSeeder())->run();
        (new CursorGroupSeeder())->run();
    }
}
