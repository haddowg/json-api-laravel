<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The `tracks` Eloquent model — the far side of the playlist pivot relation (Phase 3b).
 * A track belongs to many playlists through the shared `playlist_track` join.
 *
 * @property int                             $id
 * @property string                          $title
 * @property \Illuminate\Support\Carbon|null $released_at
 */
final class Track extends Model
{
    protected $table = 'tracks';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'released_at' => 'datetime',
    ];

    /**
     * @return BelongsToMany<Playlist, $this>
     */
    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_track', 'track_id', 'playlist_id');
    }
}
