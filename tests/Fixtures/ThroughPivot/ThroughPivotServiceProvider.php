<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Tracks on playlists through pivot rows, served by Eloquent, or with `through_pivot.store`
 * set to `memory`, by in-memory providers holding the same tracks and playlists and no pivot
 * rows.
 *
 * Track 1 is on playlist 1, added by sam and audited by audrey, and on playlist 2, added by
 * kim. Track 2 is on playlist 1, added by ghost, a user the users' scope hides. Track 3 is on
 * playlist 3, added by kim. Tracks 1 and 2 are on album 1 and track 3 on album 2.
 *
 * @internal
 */
final class ThroughPivotServiceProvider extends ServiceProvider
{
    /**
     * @var list<array{int, int, int, int, string, int, ?int}> id, playlist, track, position, added at, added by, auditor
     */
    private const array ENTRIES = [
        [1, 1, 1, 1, '2026-01-10 00:00:00', 1, 1],
        [2, 2, 1, 2, '2026-03-10 00:00:00', 2, null],
        [3, 1, 2, 2, '2026-02-10 00:00:00', 3, null],
        [4, 3, 3, 1, '2026-04-10 00:00:00', 2, null],
    ];

    public function register(): void
    {
        $resources = [TpTrackResource::class, TpPlaylistResource::class, TpAlbumResource::class, TpUserResource::class, TpAuditorResource::class];

        if (config('through_pivot.store') === 'memory') {
            JsonApi::register($resources);
            $identify = static function (object $item): string {
                $id = Accessor::get($item, 'id');

                return \is_scalar($id) ? (string) $id : '';
            };
            $playlists = [];
            foreach ([1 => 'Morning', 2 => 'Evening', 3 => 'Night'] as $id => $name) {
                $playlists[(string) $id] = (object) ['id' => (string) $id, 'name' => $name];
            }
            $albums = ['1' => (object) ['id' => '1', 'title' => 'Alpha', 'tracks' => []], '2' => (object) ['id' => '2', 'title' => 'Beta', 'tracks' => []]];
            $tracks = [];
            foreach ([1 => ['One', '1'], 2 => ['Two', '1'], 3 => ['Three', '2']] as $id => [$title, $album]) {
                $track = (object) ['id' => (string) $id, 'title' => $title, 'album' => $albums[$album], 'playlists' => [], 'cursorPlaylists' => []];
                $albums[$album]->tracks[] = $track;
                $tracks[(string) $id] = $track;
            }
            foreach (self::ENTRIES as [, $playlist, $track]) {
                $member = $tracks[(string) $track] ?? null;
                if ($member !== null) {
                    $member->playlists[] = $playlists[(string) $playlist];
                    $member->cursorPlaylists[] = $playlists[(string) $playlist];
                }
            }
            JsonApi::provider(new InMemoryDataProvider('tpTracks', $tracks, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('tpPlaylists', $playlists, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('tpAlbums', $albums, identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('tpUsers', [], identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('tpAuditors', [], identify: $identify));

            return;
        }

        JsonApi::register([...$resources, TpBrokenResource::class]);
        $models = [
            'tpTracks' => TpTrack::class,
            'tpPlaylists' => TpPlaylist::class,
            'tpAlbums' => TpAlbum::class,
            'tpUsers' => TpUser::class,
            'tpAuditors' => TpAuditor::class,
            'tpBroken' => TpAlbum::class,
        ];
        if (TpBrokenResource::$declaration === 'pivot-row-relation-several-types') {
            $models['tpUserAliases'] = TpUser::class;
        }
        if (TpBrokenResource::$declaration === 'legacy-through-several') {
            $models['tpOwners'] = TpOwner::class;
            $models['tpOwnerAliases'] = TpOwner::class;
        }
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
    }

    /**
     * Creates and seeds the tables the Eloquent store reads.
     */
    public static function migrate(): void
    {
        Schema::create('tp_users', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->boolean('hidden')->default(false);
        });
        Schema::create('tp_auditors', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('tp_playlists', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('tp_albums', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
        });
        Schema::create('tp_tracks', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('album_id')->nullable();
        });
        Schema::create('tp_entries', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('playlist_id');
            $table->unsignedInteger('track_id');
            $table->integer('position');
            $table->dateTime('added_at');
            $table->unsignedInteger('added_by_id');
            $table->unsignedInteger('auditor_id')->nullable();
        });
        Schema::create('tp_album_track', static function (Blueprint $table): void {
            $table->unsignedInteger('album_id');
            $table->unsignedInteger('track_id');
            $table->integer('position');
        });
        Schema::create('tp_taggables', static function (Blueprint $table): void {
            $table->unsignedInteger('track_id');
            $table->unsignedInteger('taggable_id');
            $table->string('taggable_type');
            $table->integer('position');
        });

        DB::table('tp_users')->insert([['id' => 1, 'name' => 'sam', 'hidden' => false], ['id' => 2, 'name' => 'kim', 'hidden' => false], ['id' => 3, 'name' => 'ghost', 'hidden' => true]]);
        DB::table('tp_auditors')->insert(['id' => 1, 'name' => 'audrey']);
        DB::table('tp_playlists')->insert([['id' => 1, 'name' => 'Morning'], ['id' => 2, 'name' => 'Evening'], ['id' => 3, 'name' => 'Night']]);
        DB::table('tp_albums')->insert([['id' => 1, 'title' => 'Alpha'], ['id' => 2, 'title' => 'Beta']]);
        DB::table('tp_tracks')->insert([['id' => 1, 'title' => 'One', 'album_id' => 1], ['id' => 2, 'title' => 'Two', 'album_id' => 1], ['id' => 3, 'title' => 'Three', 'album_id' => 2]]);
        foreach (self::ENTRIES as [$id, $playlist, $track, $position, $addedAt, $addedBy, $auditor]) {
            DB::table('tp_entries')->insert(['id' => $id, 'playlist_id' => $playlist, 'track_id' => $track, 'position' => $position, 'added_at' => $addedAt, 'added_by_id' => $addedBy, 'auditor_id' => $auditor]);
        }
    }
}
