<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

use Illuminate\Database\Eloquent\Model;

final class Book extends Model
{
    protected $table = 'fd_books';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['archived' => 'boolean', 'draft' => 'boolean'];
}
