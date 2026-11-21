<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use Illuminate\Database\Eloquent\Model;

/**
 * A type declaring no meta at all, whose meta stays open both ways.
 *
 * @property int    $id
 * @property string $name
 */
final class Plain extends Model
{
    public $timestamps = false;

    protected $table = 'tr_plains';

    protected $guarded = [];
}
