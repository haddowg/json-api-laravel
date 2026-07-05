<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `artists` Eloquent model (storage table `mc_artists`).
 *
 * @property int                             $id
 * @property string                          $name
 * @property string                          $slug
 * @property string|null                     $website
 * @property string|null                     $bio
 * @property int                             $track_count
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class Artist extends Model
{
    protected $table = 'mc_artists';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'track_count' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * @return HasMany<Album, $this>
     */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'artist_id');
    }
}
