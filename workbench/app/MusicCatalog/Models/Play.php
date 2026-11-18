<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `plays` Eloquent model (storage table `mc_plays`): a track played on air. `station`
 * keeps the id of the radio station that played it in a plain column, since stations are
 * served by a provider rather than a model, and a station reaches its plays through that
 * column as its foreign key.
 *
 * @property int                        $id
 * @property string                     $station
 * @property \Illuminate\Support\Carbon $played_at
 * @property int|null                   $track_id
 */
final class Play extends Model
{
    protected $table = 'mc_plays';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'played_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'track_id');
    }
}
