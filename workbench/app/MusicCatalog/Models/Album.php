<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `albums` Eloquent model (storage table `mc_albums`). Casts give the wire types the
 * {@see \Workbench\App\MusicCatalog\JsonApi\AlbumResource} fields expect: Carbon dates,
 * a float rating, a bool flag, and the `release_info` Map round-tripped through a single
 * JSON column (the array cast).
 *
 * @property int                             $id
 * @property int|null                        $artist_id
 * @property string                          $title
 * @property float|null                      $average_rating
 * @property string|null                     $artwork
 * @property \Illuminate\Support\Carbon      $released_at
 * @property bool                            $explicit
 * @property string                          $status
 * @property \Illuminate\Support\Carbon|null $available_from
 * @property \Illuminate\Support\Carbon|null $available_until
 * @property array<string, mixed>|null       $release_info
 */
final class Album extends Model
{
    protected $table = 'mc_albums';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'average_rating' => 'float',
        'explicit' => 'boolean',
        'released_at' => 'datetime',
        'available_from' => 'date',
        'available_until' => 'date',
        'release_info' => 'array',
    ];

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    /**
     * @return HasMany<Track, $this>
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class, 'album_id');
    }
}
