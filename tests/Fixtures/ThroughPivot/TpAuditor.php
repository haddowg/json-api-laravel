<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Model;

final class TpAuditor extends Model
{
    protected $table = 'tp_auditors';

    public $timestamps = false;

    protected $guarded = [];
}
