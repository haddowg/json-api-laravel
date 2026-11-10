<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Template extends Model
{
    protected $table = 'tf_templates';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
