<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Model;

final class Vault extends Model
{
    protected $table = 'ta_vaults';

    public $timestamps = false;

    protected $guarded = [];
}
