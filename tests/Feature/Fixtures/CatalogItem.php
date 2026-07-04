<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The child of {@see Owner}'s camelCase `catalogItems` HasMany.
 *
 * @property int $id
 * @property int $owner_id
 */
final class CatalogItem extends Model
{
    protected $table = 'cc_catalog_items';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
