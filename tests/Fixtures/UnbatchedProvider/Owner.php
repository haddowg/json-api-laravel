<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Owner extends Model
{
    protected $table = 'ub_owners';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
