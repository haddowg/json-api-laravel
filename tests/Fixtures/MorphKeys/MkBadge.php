<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property bool   $hidden
 */
final class MkBadge extends Model
{
    protected $table = 'mk_badges';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleScope());
    }
}
