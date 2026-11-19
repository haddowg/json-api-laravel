<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * An album; its broken twin type reads the same table through relations the warm-up refuses
 * to reach a pivot row through.
 */
final class TpAlbum extends Model
{
    protected $table = 'tp_albums';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<TpTrack, $this>
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(TpTrack::class, 'album_id');
    }

    /**
     * Album tracks over a join table that holds a position and nothing else.
     *
     * @return BelongsToMany<TpTrack, $this>
     */
    public function listedTracks(): BelongsToMany
    {
        return $this->belongsToMany(TpTrack::class, 'tp_album_track', 'album_id', 'track_id')->withPivot('position');
    }

    /**
     * @return MorphToMany<TpTrack, $this>
     */
    public function taggedTracks(): MorphToMany
    {
        return $this->morphToMany(TpTrack::class, 'taggable', 'tp_taggables', 'taggable_id', 'track_id')->withPivot('position');
    }
}
