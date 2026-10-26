<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StoredUnique;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $code
 * @property string|null $region
 * @property bool        $archived
 */
final class Coupon extends Model
{
    public $timestamps = false;

    protected $table = 'su_coupons';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @param Builder<self> $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('archived', false);
    }
}
