<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedTargetServability;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A shelf whose `crates` relation exposes `GET /shelves/{id}/crates` to a type the server
 * never registers. That endpoint would have to return a `crates` resource object, and the
 * server has neither a serializer to render one nor a field inventory to describe one — so
 * the {@see \haddowg\JsonApiLaravel\Server\ServableResourceWarmer} reports it and
 * `jsonapi:optimize` fails the deploy.
 *
 * Its `pallets` sibling is the contrast: the same unregistered target declared
 * **linkage-only**, which is fine — a linkage `{type: pallets, id}` asserts no shape.
 */
#[AsJsonApiResource(readOnly: true)]
final class ShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            HasMany::make('crates', 'crates'),
            HasMany::make('pallets', 'pallets')->withoutRelatedEndpoint(),
        ];
    }
}
