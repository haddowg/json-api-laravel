<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

use Illuminate\Database\Eloquent\Model;

final class Author extends Model
{
    protected $table = 'ufn_authors';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
