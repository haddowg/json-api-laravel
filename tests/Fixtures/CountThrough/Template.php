<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Template extends Model
{
    protected $table = 'ct_templates';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
