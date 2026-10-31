<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user whose companies span three types, stored as type and id join rows.
 *
 * @property int    $id
 * @property string $name
 */
final class MtmUser extends Model
{
    protected $table = 'mtm_users';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<MtmUserCompany, $this>
     */
    public function userCompanies(): HasMany
    {
        return $this->hasMany(MtmUserCompany::class, 'mtm_user_id')->orderBy('id');
    }
}
