<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use Illuminate\Database\Eloquent\Model;

/**
 * A pin points at a board or a card through two plain columns and lists its tags in a JSON
 * column. Neither is an Eloquent relation, so only the resource's fill hooks can write them.
 *
 * @property int              $id
 * @property string           $label
 * @property string|null      $target_kind
 * @property int|null         $target_key
 * @property list<int>|null   $tag_list
 */
final class Pin extends Model
{
    public $timestamps = false;

    protected $table = 'fu_pins';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = ['tag_list' => 'array'];
}
