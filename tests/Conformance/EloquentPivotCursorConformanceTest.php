<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\PivotCursorConformanceEloquentServiceProvider;
use Workbench\Database\Seeders\CursorBoardSeeder;
use Workbench\Database\Seeders\CursorWidgetSeeder;

/**
 * {@see PivotCursorConformanceTestCase} against the **reference Eloquent provider**
 * executed as real SQL over in-memory SQLite: the `cursorBoards.widgets`
 * belongsToMany's pivot-joined query carries the keyset WHERE + the forced
 * NULL=largest ORDER BY on top of the `cursor_board_widget` INNER JOIN, and the
 * handler's `meta.pivot` wrap reads each member's stored `position` off the join. It
 * seeds the SAME {@see \Workbench\App\Support\ConformanceFixtures::cursorBoards()}
 * partition the in-memory witness carries ({@see CursorWidgetSeeder} then
 * {@see CursorBoardSeeder}), so every inherited assertion must produce the IDENTICAL
 * pivot-scoped keyset page — `meta.pivot` included (docs/adr/0017).
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPivotCursorConformanceTest extends PivotCursorConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return PivotCursorConformanceEloquentServiceProvider::class;
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
        (new CursorBoardSeeder())->run();
    }
}
