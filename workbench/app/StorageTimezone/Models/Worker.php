<?php

declare(strict_types=1);

namespace Workbench\App\StorageTimezone\Models;

use Illuminate\Database\Eloquent\Model;

final class Worker extends Model
{
    protected $table = 'tz_workers';

    public $timestamps = false;

    protected $guarded = [];
}
