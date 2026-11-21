<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class TpTrack extends Model
{
    protected $table = 'tp_tracks';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<TpAlbum, $this>
     */
    public function album(): BelongsTo
    {
        return $this->belongsTo(TpAlbum::class, 'album_id');
    }

    /**
     * @return BelongsToMany<TpPlaylist, $this, TpEntry>
     */
    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(TpPlaylist::class, 'tp_entries', 'track_id', 'playlist_id')
            ->using(TpEntry::class)
            ->withPivot('id', 'position', 'added_at', 'added_by_id', 'auditor_id');
    }

    /**
     * @return BelongsToMany<TpPlaylist, $this, TpEntry>
     */
    public function cursorPlaylists(): BelongsToMany
    {
        return $this->playlists();
    }
}
