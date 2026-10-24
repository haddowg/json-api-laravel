<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

use Illuminate\Database\Eloquent\Model;

final class Comment extends Model
{
    protected $table = 'ufn_comments';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
