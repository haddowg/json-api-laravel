<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use Illuminate\Database\Eloquent\Model;

/**
 * An item whose resource meta is open: any member, as long as it is an integer.
 *
 * @property int                  $id
 * @property string               $name
 * @property array<string, mixed> $extras
 */
final class Item extends Model
{
    public $timestamps = false;

    protected $table = 'tr_items';

    protected $guarded = [];

    protected $attributes = ['extras' => '[]'];

    protected $casts = ['extras' => 'array'];
}
