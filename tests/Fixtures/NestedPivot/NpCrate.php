<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class NpCrate extends Model
{
    protected $table = 'np_crates';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<NpRecord, $this>
     */
    public function records(): BelongsToMany
    {
        return $this->belongsToMany(NpRecord::class, 'np_crate_record', 'crate_id', 'record_id')
            ->withPivot('position', 'added_at');
    }
}
