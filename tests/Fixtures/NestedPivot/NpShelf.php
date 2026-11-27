<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NpShelf extends Model
{
    protected $table = 'np_shelves';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<NpCrate, $this>
     */
    public function crate(): BelongsTo
    {
        return $this->belongsTo(NpCrate::class, 'crate_id');
    }

    /**
     * @return BelongsTo<NpCrate, $this>
     */
    public function altCrate(): BelongsTo
    {
        return $this->belongsTo(NpCrate::class, 'alt_crate_id');
    }
}
