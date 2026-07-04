<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The `playlists` Eloquent model — the parent of the Phase-3b pivot + relationship-mutation
 * surface. Two `belongsToMany` relations over SEPARATE join tables, so a mutation of one never
 * changes the other (matching the in-memory witness's two independent member lists):
 *  - {@see orderedTracks()} joins through `playlist_track`, carrying the writable
 *    `position`/`weight` + server-owned `added_at` pivot columns (`withPivot`), so its linkage
 *    renders `meta.pivot` and a mutation upserts those columns;
 *  - {@see tracks()} joins through the bare `playlist_track_plain` (no pivot columns) — the
 *    plain-belongsToMany witness and the relationship-existence filter target — so an id-only
 *    `sync()` inserts with no NOT-NULL `position` to satisfy.
 *
 * @property int    $id
 * @property string $title
 * @property bool   $public
 */
final class Playlist extends Model
{
    protected $table = 'playlists';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'public' => 'boolean',
    ];

    /**
     * The pivot-bearing membership: `withPivot` surfaces the join columns so the pivot
     * accessor hydrates them for the `meta.pivot` read and the mutation upsert.
     *
     * @return BelongsToMany<Track, $this>
     */
    public function orderedTracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_track', 'playlist_id', 'track_id')
            ->withPivot('position', 'weight', 'added_at');
    }

    /**
     * The bare join over `playlist_track_plain` — no pivot columns — the plain-belongsToMany
     * witness and the relationship-existence filter target. Its own table (not the pivot
     * `playlist_track`) keeps a `tracks` mutation independent of `orderedTracks` and lets an
     * id-only `sync()` insert with no NOT-NULL `position` column.
     *
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_track_plain', 'playlist_id', 'track_id');
    }
}
