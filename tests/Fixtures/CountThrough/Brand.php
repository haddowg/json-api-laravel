<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Brand extends Model
{
    protected $table = 'ct_brands';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
