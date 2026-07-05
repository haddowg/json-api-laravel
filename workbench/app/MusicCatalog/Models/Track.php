<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The `tracks` Eloquent model (storage table `mc_tracks`). `genres` is a JSON array; the
 * `playlists` relation is the bare join (no pivot columns), the inverse of a playlist's
 * plain `tracks`.
 *
 * @property int          $id
 * @property int|null     $album_id
 * @property string       $title
 * @property int          $track_number
 * @property int          $length_seconds
 * @property bool         $explicit
 * @property list<string> $genres
 * @property string|null  $preview_offset
 */
final class Track extends Model
{
    protected $table = 'mc_tracks';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'track_number' => 'integer',
        'length_seconds' => 'integer',
        'explicit' => 'boolean',
        'genres' => 'array',
    ];

    /**
     * @return BelongsTo<Album, $this>
     */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'album_id');
    }

    /**
     * @return BelongsToMany<Playlist, $this>
     */
    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'mc_playlist_track_plain', 'track_id', 'playlist_id');
    }
}
