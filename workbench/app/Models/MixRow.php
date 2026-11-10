<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mix link row, on the Eloquent provider and, unsaved, on the in-memory witness.
 *
 * @property int      $id
 * @property int      $queue_id
 * @property string   $item_type
 * @property int      $song_id
 * @property int      $position
 */
final class MixRow extends Model
{
    protected $table = 'pq_mix_rows';

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
