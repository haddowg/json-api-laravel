<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use Illuminate\Database\Eloquent\Model;

final class FpComment extends Model
{
    protected $table = 'fp_comments';

    public $timestamps = false;

    protected $guarded = [];
}
