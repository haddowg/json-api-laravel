<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $mtm_user_id
 * @property string $company_type
 * @property int    $company_id
 */
final class MtmUserCompany extends Model
{
    protected $table = 'mtm_user_companies';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
