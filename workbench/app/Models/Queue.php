<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 */
final class Queue extends Model
{
    protected $table = 'pq_queues';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Song, $this>
     */
    public function songs(): HasMany
    {
        return $this->hasMany(Song::class, 'queue_id');
    }

    /**
     * @return BelongsToMany<Song, $this>
     */
    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'pq_favourites', 'queue_id', 'song_id')->withPivot('position');
    }

    /**
     * @return HasMany<MixRow, $this>
     */
    public function mixRows(): HasMany
    {
        return $this->hasMany(MixRow::class, 'queue_id')->orderBy('id');
    }
}
