<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loose mix link row, on the Eloquent provider and, unsaved, on the in-memory witness.
 *
 * @property int      $id
 * @property int      $queue_id
 * @property int      $song_id
 * @property int|null $position
 */
final class LooseMixRow extends Model
{
    protected $table = 'pq_loose_mix_rows';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = ['song_id' => 'integer', 'position' => 'integer'];

    /**
     * @return BelongsTo<Queue, $this>
     */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id');
    }
}
