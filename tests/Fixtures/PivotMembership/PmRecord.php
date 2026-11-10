<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $title
 * @property bool   $hidden
 */
#[ScopedBy(HidesHiddenRecords::class)]
final class PmRecord extends Model
{
    protected $table = 'pm_records';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
