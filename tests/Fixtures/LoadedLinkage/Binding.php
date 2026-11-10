<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use Illuminate\Database\Eloquent\Model;

final class Binding extends Model
{
    protected $table = 'll_bindings';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['hidden' => 'boolean', 'detached' => 'boolean'];
}
