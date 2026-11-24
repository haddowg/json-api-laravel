<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use Illuminate\Database\Eloquent\Model;

/**
 * A code keyed by a string, so a text morph column compares with its key as it is.
 *
 * @property string $id
 * @property string $name
 * @property bool   $hidden
 */
final class MkCode extends Model
{
    protected $table = 'mk_codes';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleScope());
    }
}
