<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\MusicCatalog\Support\ProductIdCodec;

/**
 * The `products` resource type (music-catalog domain) — the encoded-id witness: a
 * database-generated integer key never on the wire, encoded to an opaque `prod-…` token via
 * {@see ProductIdCodec}, with `matchAs()` pinning the route `{id}` to the token shape. A
 * self-referential `parent` to-one exercises the linkage decode on a relationship write.
 */
#[AsJsonApiResource]
final class ProductResource extends AbstractResource
{
    public static string $type = 'products';

    public function fields(): array
    {
        return [
            Id::make()
                ->encodeUsing(new ProductIdCodec())
                ->matchAs('prod-[0-9a-f]+'),
            Str::make('name')->required(),
            BelongsTo::make('parent', 'products')->nullable(),
        ];
    }
}
