<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A parent whose countable relation method is **camelCase** ({@see catalogItems()}) — the
 * shape that exposes withCount()'s snake_case aliasing: `withCount(['catalogItems'])` names
 * the column `catalog_items_count`, not `catalogItems_count`. Backs
 * {@see \haddowg\JsonApiLaravel\Tests\Feature\EloquentCamelCaseWithCountTest}.
 *
 * @property int $id
 */
final class Owner extends Model
{
    protected $table = 'cc_owners';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<CatalogItem, $this>
     */
    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class, 'owner_id');
    }
}
