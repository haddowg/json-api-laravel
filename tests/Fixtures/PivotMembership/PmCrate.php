<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int    $id
 * @property string $name
 */
final class PmCrate extends Model
{
    protected $table = 'pm_crates';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsToMany<PmRecord, $this, CrateRecord>
     */
    public function records(): BelongsToMany
    {
        return $this->belongsToMany(PmRecord::class, 'pm_crate_records', 'pm_crate_id', 'pm_record_id')
            ->using(CrateRecord::class)
            ->withPivot('id', 'slot');
    }
}
