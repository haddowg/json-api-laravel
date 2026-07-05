<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The `playlists` Eloquent model (storage table `mc_playlists`) — a string (UUID) primary
 * key the resource's `Id::make()->uuid()->generated()` mints. `tracks` is the plain
 * belongsToMany (bare join); `orderedTracks` is the pivot-bearing belongsToMany carrying
 * `position`/`weight`/`added_at`.
 *
 * @property string      $id
 * @property int|null    $owner_id
 * @property string      $title
 * @property string      $slug
 * @property bool        $public
 * @property string|null $external_id
 */
final class Playlist extends Model
{
    protected $table = 'mc_playlists';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'mc_playlist_track_plain', 'playlist_id', 'track_id');
    }

    /**
     * @return BelongsToMany<Track, $this>
     */
    public function orderedTracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'mc_playlist_track', 'playlist_id', 'track_id')
            ->withPivot('position', 'weight', 'added_at');
    }
}
