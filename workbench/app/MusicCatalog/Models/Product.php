<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `products` Eloquent model (storage table `mc_products`) — a database-generated
 * integer key that never reaches the wire (the resource encodes it to a `prod-…` token via
 * {@see \Workbench\App\MusicCatalog\Support\ProductIdCodec}). A self-referential `parent`
 * exercises the linkage decode on a relationship write.
 *
 * @property int      $id
 * @property int|null $parent_id
 * @property string   $name
 */
final class Product extends Model
{
    protected $table = 'mc_products';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }
}
