<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `artists` Eloquent model (storage table `mc_artists`).
 *
 * `slug` is optional on create. A new artist saved without one gets it derived from `name`
 * by the `creating` listener below, suffixed (`-2`, `-3`, ...) when another artist already
 * holds it, so `filter[slug]` keeps matching one artist. The resource declares
 * `minLength(1)`, so a write that sends an empty slug is a `422`.
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

    protected static function booted(): void
    {
        static::creating(static function (self $artist): void {
            $current = $artist->getAttribute('slug');
            if (\is_string($current) && $current !== '') {
                return;
            }

            $base = \trim(\preg_replace('/[^a-z0-9]+/', '-', \strtolower($artist->name)) ?? '', '-');
            if ($base === '') {
                $base = 'artist';
            }

            // An atomic batch saves each artist as its operation runs, inside the batch's
            // transaction, so this also sees artists created earlier in the same batch.
            /** @var list<string> $taken */
            $taken = static::query()
                ->where('slug', $base)
                ->orWhere('slug', 'like', $base . '-%')
                ->pluck('slug')
                ->all();

            $slug = $base;
            for ($n = 2; \in_array($slug, $taken, true); ++$n) {
                $slug = $base . '-' . $n;
            }
            $artist->slug = $slug;
        });
    }

    /**
     * @return HasMany<Album, $this>
     */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'artist_id');
    }
}
