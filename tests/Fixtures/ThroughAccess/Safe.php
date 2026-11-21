<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Model;

final class Safe extends Model
{
    protected $table = 'ta_safes';

    public $timestamps = false;

    protected $guarded = [];
}
