<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 */
final class Brand extends Model
{
    protected $table = 'tf_brands';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
