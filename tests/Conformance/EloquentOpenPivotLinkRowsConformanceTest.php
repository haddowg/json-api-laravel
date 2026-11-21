<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\LinkRows\Fixtures;
use Workbench\App\Models\Board;
use Workbench\App\Models\Note;
use Workbench\App\Models\Photo;
use Workbench\App\Providers\LinkRowsEloquentServiceProvider;

/**
 * {@see OpenPivotLinkRowsConformanceTestCase} against the reference Eloquent provider.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentOpenPivotLinkRowsConformanceTest extends OpenPivotLinkRowsConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return LinkRowsEloquentServiceProvider::class;
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
        foreach (Fixtures::notes() as $id => $title) {
            Note::query()->create(['id' => $id, 'title' => $title]);
        }
        foreach (Fixtures::photos() as $id => $caption) {
            Photo::query()->create(['id' => $id, 'caption' => $caption]);
        }
        foreach (Fixtures::boards() as $id => $row) {
            $board = Board::query()->create(['id' => $id, 'name' => $row['name']]);
            foreach ($row['items'] as $item) {
                $board->boardItems()->create(['item_type' => $item['type'], 'item_id' => $item['id'], 'position' => $item['position'], 'label' => $item['label']]);
            }
        }
    }
}
