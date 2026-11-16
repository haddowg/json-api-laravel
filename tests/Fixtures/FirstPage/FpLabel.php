<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use Illuminate\Database\Eloquent\Model;

final class FpLabel extends Model
{
    protected $table = 'fp_labels';

    public $timestamps = false;

    protected $guarded = [];
}
