<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A seat placing its `peer` beside its `owner`, two writers.
 */
final class IkWriterSeat extends Pivot
{
    public $timestamps = false;

    protected $table = 'ik_writer_seats';

    /**
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(IdKinds::model('writer'), 'owner_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function peer(): BelongsTo
    {
        return $this->belongsTo(IdKinds::model('writer'), 'peer_id');
    }
}
