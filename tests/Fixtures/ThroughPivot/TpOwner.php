<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Model;

/**
 * A model no registered type serves, which a pivot row can still relate to.
 */
final class TpOwner extends Model
{
    protected $table = 'tp_users';

    public $timestamps = false;

    protected $guarded = [];
}
