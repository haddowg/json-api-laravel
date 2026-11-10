<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\MixRow;
use Workbench\App\Models\Queue;
use Workbench\App\Models\Song;
use Workbench\App\Positioned\Fixtures;
use Workbench\App\Providers\PositionedEloquentServiceProvider;

/**
 * {@see HiddenPositionConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite, song 2 hidden by a global scope, every position unique per queue.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentHiddenPositionConformanceTest extends HiddenPositionConformanceTestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Fixtures::queues() as $id => $row) {
            Queue::query()->create(['id' => $id, 'name' => $row['name']]);
        }
        foreach (Fixtures::songs() as $id => $row) {
            Song::query()->create(['id' => $id, 'title' => $row['title'], 'queue_id' => $row['queue'], 'slot' => $row['slot']]);
        }
        foreach (Fixtures::queues()[1]['mix'] as $position => $songId) {
            Queue::query()->findOrFail(1)->mixRows()->create(['item_type' => 'songs', 'song_id' => $songId, 'position' => $position]);
        }

        Song::addGlobalScope('visible', static fn(Builder $query): Builder => $query->where('id', '!=', 2));
    }

    protected function tearDown(): void
    {
        Song::clearBootedModels();
        parent::tearDown();
    }

    protected function storedMix(): array
    {
        /** @var array<int, int> $positions */
        $positions = MixRow::query()->where('queue_id', 1)->orderBy('position')->pluck('position', 'song_id')->all();

        return $positions;
    }
}
