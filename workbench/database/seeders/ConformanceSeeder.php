<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\Genre;
use Workbench\App\Models\Playlist;
use Workbench\App\Models\Track;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the richer {@see ConformanceFixtures} rows into the Eloquent tables — the
 * SAME rows the in-memory {@see \Workbench\App\Providers\ConformanceInMemoryServiceProvider}
 * carries, so the Eloquent half of the read-conformance suite reads identical data.
 *
 * Distinct from the Phase-0 {@see MusicCatalogSeeder} (the minimal 2-row set the
 * feature suite asserts against), so the conformance dataset never perturbs the
 * existing tests.
 */
final class ConformanceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConformanceFixtures::artists() as $row) {
            Artist::query()->create($row);
        }

        foreach (ConformanceFixtures::albums() as $row) {
            Album::query()->create($row);
        }

        foreach (ConformanceFixtures::genres() as $row) {
            Genre::query()->create($row);
        }

        foreach (ConformanceFixtures::tracks() as $row) {
            Track::query()->create($row);
        }

        foreach (ConformanceFixtures::playlists() as $row) {
            Playlist::query()->create($row);
        }

        // The pivot rows carry the writable `position`/`weight` + server-owned `added_at`
        // columns; seeded directly so the Eloquent half reads the SAME memberships the
        // in-memory object graph holds (the in-memory witness stores no pivot meta). The bare
        // `playlist_track_plain` join is seeded from the SAME membership (id pairs only), so the
        // `tracks`/`lockedTracks` relations read the identical set the in-memory `$tracks` list
        // holds — the two relations stay in lockstep on reads yet independent under mutation.
        foreach (ConformanceFixtures::playlistTracks() as $row) {
            DB::table('playlist_track')->insert([
                'playlist_id' => $row['playlist_id'],
                'track_id' => $row['track_id'],
                'position' => $row['position'],
                'weight' => $row['weight'],
                'added_at' => $row['added_at']->format('Y-m-d H:i:s'),
            ]);

            DB::table('playlist_track_plain')->insert([
                'playlist_id' => $row['playlist_id'],
                'track_id' => $row['track_id'],
            ]);
        }
    }
}
