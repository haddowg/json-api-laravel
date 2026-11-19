<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy(PublicTagsScope::class)]
final class Tag extends Model
{
    protected $table = 'ta_tags';

    public $timestamps = false;

    protected $guarded = [];
}
