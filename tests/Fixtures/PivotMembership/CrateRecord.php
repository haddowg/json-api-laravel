<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A crate's link row, with its own incrementing id.
 *
 * @property int      $id
 * @property int      $pm_crate_id
 * @property int      $pm_record_id
 * @property int|null $slot
 */
final class CrateRecord extends Pivot
{
    public $incrementing = true;

    public $timestamps = false;

    protected $table = 'pm_crate_records';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
