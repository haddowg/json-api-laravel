<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `albums` Eloquent model. Casts give the wire types the {@see \Workbench\App\JsonApi\AlbumResource}
 * fields expect with zero adapter code: `available_from`/`released_at` are Carbon
 * (serialized by the Date/DateTime fields), `explicit` a bool, `average_rating` a float.
 *
 * @property int $id
 */
final class Album extends Model
{
    protected $table = 'albums';

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
        'available_from' => 'date',
        'released_at' => 'datetime',
    ];

    /**
     * The album's artist — declared for relationship-existence filters; the
     * resource-level relation declaration is Phase 3.
     *
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }
}
