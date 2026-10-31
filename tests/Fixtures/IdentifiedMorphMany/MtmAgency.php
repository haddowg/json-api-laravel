<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property string $code
 * @property bool   $hidden
 */
class MtmAgency extends Model
{
    protected $table = 'mtm_agencies';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
