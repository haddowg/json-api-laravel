<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\MusicCatalog\Models\Album;
use Workbench\App\MusicCatalog\Models\Artist;
use Workbench\App\MusicCatalog\Models\Device;
use Workbench\App\MusicCatalog\Models\Favorite;
use Workbench\App\MusicCatalog\Models\Genre;
use Workbench\App\MusicCatalog\Models\Library;
use Workbench\App\MusicCatalog\Models\Playlist;
use Workbench\App\MusicCatalog\Models\Product;
use Workbench\App\MusicCatalog\Models\Release;
use Workbench\App\MusicCatalog\Models\Track;
use Workbench\App\MusicCatalog\Models\User;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * Seeds the full music-catalog domain (decision 14) into the `mc_`-prefixed Eloquent tables
 * — the same canonical {@see Fixtures} the in-memory wiring carries, so `testbench serve`
 * and the dual-provider feature suite serve identical data. Polymorphic members
 * (`favorites.favoritable`, `libraries.items`) are stamped with the morph aliases the
 * wiring registers.
 */
final class McCatalogSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const array ALIAS_BY_TYPE = [
        'tracks' => 'mc_track',
        'albums' => 'mc_album',
        'artists' => 'mc_artist',
    ];

    public function run(): void
    {
        foreach (Fixtures::artists() as $row) {
            Artist::query()->create($row);
        }
        foreach (Fixtures::albums() as $row) {
            Album::query()->create($row);
        }
        foreach (Fixtures::releases() as $row) {
            Release::query()->create($row);
        }
        foreach (Fixtures::tracks() as $row) {
            Track::query()->create($row);
        }
        foreach (Fixtures::genres() as $row) {
            Genre::query()->create($row);
        }
        foreach (Fixtures::devices() as $row) {
            Device::query()->create($row);
        }
        foreach (Fixtures::products() as $row) {
            Product::query()->create($row);
        }
        foreach (Fixtures::users() as $row) {
            User::query()->create($row);
        }
        foreach (Fixtures::libraries() as $row) {
            Library::query()->create($row);
        }
        foreach (Fixtures::playlists() as $row) {
            Playlist::query()->create($row);
        }

        foreach (Fixtures::favorites() as $row) {
            $type = $row['favoritable_type'];
            Favorite::query()->create([
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'favorited_at' => $row['favorited_at'],
                'favoritable_type' => $type !== null ? (self::ALIAS_BY_TYPE[$type] ?? $type) : null,
                'favoritable_id' => $row['favoritable_id'],
            ]);
        }

        // The plain playlists.tracks join.
        foreach (Fixtures::plainTracks() as $row) {
            $playlist = Playlist::query()->find($row['playlist_id']);
            $playlist?->tracks()->attach($row['track_id']);
        }

        // The pivot-bearing playlists.orderedTracks join (position/weight/added_at) — the same
        // canonical rows the in-memory graph carries as Playlist::$entries, so both arms (and
        // the docker demo) serve GET /playlists/{id}/orderedTracks with real pivot data.
        foreach (Fixtures::orderedTracks() as $row) {
            $playlist = Playlist::query()->find($row['playlist_id']);
            $playlist?->orderedTracks()->attach($row['track_id'], [
                'position' => $row['position'],
                'weight' => $row['weight'],
                'added_at' => $row['added_at']?->format('Y-m-d H:i:s'),
            ]);
        }

        // The mixed polymorphic libraries.items (morphedByMany writes each member's morph
        // alias into `item_type`).
        foreach (Fixtures::libraryItems() as $libraryId => $items) {
            $library = Library::query()->find($libraryId);
            if ($library === null) {
                continue;
            }
            foreach ($items as $item) {
                match ($item['type']) {
                    'tracks' => $library->itemTracks()->attach($item['id']),
                    'albums' => $library->itemAlbums()->attach($item['id']),
                    'artists' => $library->itemArtists()->attach($item['id']),
                    default => null,
                };
            }
        }
    }
}
