<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The convention match for the `imports` fixture type — it EXISTS (so the convention
 * tier maps it and the auto pair claims `imports` at `-256`) but is deliberately backed
 * by **no table**: the explicit in-memory registration at the default priority `0` must
 * shadow the auto pair, so a request served through this model would blow up on the
 * missing table. A green `imports` read is therefore proof of shadowing, not survival.
 *
 * @property int    $id
 * @property string $title
 *
 * @internal
 */
final class Import extends Model
{
    protected $table = 'imports';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
