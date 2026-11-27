<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class NpRecord extends Model
{
    protected $table = 'np_records';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<NpCrate, $this>
     */
    public function crates(): BelongsToMany
    {
        return $this->belongsToMany(NpCrate::class, 'np_crate_record', 'record_id', 'crate_id');
    }
}
