<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A track's place on a playlist: when it was added, by whom, and who audited it.
 */
final class TpEntry extends Pivot
{
    protected $table = 'tp_entries';

    public $timestamps = false;

    public $incrementing = true;

    protected $guarded = [];

    protected $casts = ['added_at' => 'datetime', 'position' => 'integer'];

    /**
     * @return BelongsTo<TpUser, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(TpUser::class, 'added_by_id');
    }

    /**
     * @return BelongsTo<TpAuditor, $this>
     */
    public function auditor(): BelongsTo
    {
        return $this->belongsTo(TpAuditor::class, 'auditor_id');
    }

    /**
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /**
     * @return BelongsTo<TpOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TpOwner::class, 'added_by_id');
    }
}
