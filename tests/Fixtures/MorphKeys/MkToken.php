<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property bool   $hidden
 */
final class MkToken extends Model
{
    protected $table = 'mk_tokens';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
