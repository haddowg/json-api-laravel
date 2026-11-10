<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Queue;
use Workbench\App\Models\Song;
use Workbench\App\Positioned\Fixtures;
use Workbench\App\Providers\PositionedEloquentServiceProvider;

/**
 * {@see PositionedConformanceTestCase} against the reference Eloquent provider over in-memory
 * SQLite, every position unique per queue.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentPositionedConformanceTest extends PositionedConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return PositionedEloquentServiceProvider::class;
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
        foreach (Fixtures::queues() as $id => $row) {
            Queue::query()->create(['id' => $id, 'name' => $row['name']]);
        }
        foreach (Fixtures::songs() as $id => $row) {
            Song::query()->create(['id' => $id, 'title' => $row['title'], 'queue_id' => $row['queue'], 'slot' => $row['slot']]);
        }
        foreach (Fixtures::queues() as $id => $row) {
            $queue = Queue::query()->findOrFail($id);
            foreach ($row['favourites'] as $position => $songId) {
                $queue->favourites()->attach($songId, ['position' => $position]);
            }
            foreach ($row['mix'] as $position => $songId) {
                $queue->mixRows()->create(['song_id' => $songId, 'position' => $position]);
            }
        }
    }
}
