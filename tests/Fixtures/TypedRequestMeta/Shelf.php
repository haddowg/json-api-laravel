<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A shelf whose items' join rows keep a position and any integer member a write sends.
 *
 * @property int    $id
 * @property string $name
 */
final class Shelf extends Model
{
    public $timestamps = false;

    protected $table = 'tr_shelves';

    protected $guarded = [];

    /**
     * @return BelongsToMany<Item, $this, ShelfItem>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'tr_shelf_item', 'shelf_id', 'item_id')
            ->using(ShelfItem::class)
            ->withPivot('position', 'extras');
    }
}
