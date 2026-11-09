<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Playlist;
use Workbench\App\Domain\Track;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Registers {@see CrateResource} on the in-memory witness, over a playlist graph built from
 * the same {@see ConformanceFixtures} rows the Eloquent half seeds.
 *
 * @internal
 */
final class ComputedPivotInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CrateResource::class]);

        $tracks = [];
        foreach (ConformanceFixtures::tracks() as $row) {
            $tracks[(string) $row['id']] = new Track(id: (string) $row['id'], title: $row['title'], released_at: $row['released_at']);
        }

        $crates = [];
        foreach (ConformanceFixtures::playlists() as $row) {
            $crates[(string) $row['id']] = new Playlist(id: (string) $row['id'], title: $row['title'], public: $row['public']);
        }

        foreach (ConformanceFixtures::playlistTracks() as $row) {
            $crate = $crates[(string) $row['playlist_id']] ?? null;
            $track = $tracks[(string) $row['track_id']] ?? null;
            if ($crate !== null && $track !== null) {
                $crate->orderedTracks[] = $track;
                $crate->tracks[] = $track;
            }
        }

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $provider = new InMemoryDataProvider('crates', $crates, identify: $identify);
        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister(
            'crates',
            $provider->store(),
            static fn(): Playlist => new Playlist(),
            static fn(string $type, string $id): ?object => $type === 'tracks' ? ($tracks[$id] ?? null) : null,
        ));
    }
}
