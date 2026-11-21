<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use Illuminate\Database\Eloquent\Model;

final class ThrTemplate extends Model
{
    protected $table = 'thr_templates';

    public $timestamps = false;

    protected $guarded = [];
}
