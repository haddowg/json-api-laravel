<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\RelatedCursorConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\CursorGroupSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * {@see CursorIncludeConformanceTestCase} against the reference Eloquent provider
 * executed as real SQL over in-memory SQLite: each parent's included cursor page is
 * minted through the per-parent keyset push-down (the same `cursorGroups.widgets`
 * scoped keyset the related endpoint runs), so every inherited assertion must produce
 * the IDENTICAL page the in-memory witness renders.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentCursorIncludeConformanceTest extends CursorIncludeConformanceTestCase
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
