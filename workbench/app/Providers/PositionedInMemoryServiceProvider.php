<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemorySnapshotCoordinator;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\LooseMixRow;
use Workbench\App\Models\MixRow;
use Workbench\App\Positioned\Fixtures;
use Workbench\App\Positioned\Queue;
use Workbench\App\Positioned\QueueResource;
use Workbench\App\Positioned\Song;
use Workbench\App\Positioned\SongResource;
use Workbench\App\Positioned\VisibleSongsOnly;

/**
 * The in-memory half of the positioned relation conformance wiring, seeded from
 * {@see Fixtures}. When {@see hiddenSongs()} names any, the songs are served by a provider of
 * their own that hides them.
 */
class PositionedInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([QueueResource::class, SongResource::class]);

        $songs = [];
        foreach (Fixtures::songs() as $id => $row) {
            $songs[(string) $id] = new Song((string) $id, $row['title'], $row['slot']);
        }

        $queues = [];
        foreach (Fixtures::queues() as $id => $row) {
            $held = [];
            foreach (Fixtures::songs() as $songId => $song) {
                if ($song['queue'] === $id) {
                    $held[] = $songs[(string) $songId];
                }
            }
            $queues[(string) $id] = new Queue(
                (string) $id,
                $row['name'],
                $held,
                \array_map(static fn(int $songId, int $position): MixRow => new MixRow(['item_type' => 'songs', 'song_id' => $songId, 'position' => $position]), $row['mix'], \array_keys($row['mix'])),
                \array_map(static fn(int $songId, int $position): LooseMixRow => new LooseMixRow(['song_id' => $songId, 'position' => $position]), $row['mix'], \array_keys($row['mix'])),
            );
        }

        /** @var InMemorySnapshotCoordinator $coordinator */
        $coordinator = $this->app->make(InMemorySnapshotCoordinator::class);

        $songProvider = new InMemoryDataProvider('songs', $songs, coordinator: $coordinator);
        $queueProvider = new InMemoryDataProvider('queues', $queues, identify: static fn(object $queue): string => $queue instanceof Queue ? $queue->id : '', coordinator: $coordinator);
        $hidden = $this->hiddenSongs();
        JsonApi::provider($hidden === [] ? $songProvider : new VisibleSongsOnly($songs, $hidden));
        JsonApi::provider($queueProvider);

        $related = static fn(string $type, string $id): ?object => $type === 'songs' ? $songProvider->store()->find($id) : null;
        JsonApi::persister(new InMemoryDataPersister('queues', $queueProvider->store(), static fn(): Queue => new Queue(), $related));
    }

    /**
     * The songs a scope hides, none by default.
     *
     * @return list<string>
     */
    protected function hiddenSongs(): array
    {
        return [];
    }
}
