<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Keeper extends Model
{
    protected $table = 'wo_keepers';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
