<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Model;

final class Ledger extends Model
{
    protected $table = 'ta_ledgers';

    public $timestamps = false;

    protected $guarded = [];
}
