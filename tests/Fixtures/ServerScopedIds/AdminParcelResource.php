<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedIds;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The same `parcels` type on the `admin` server, with `p-` prefixed wire ids.
 */
#[AsJsonApiResource(server: 'admin')]
final class AdminParcelResource extends AbstractResource
{
    public static string $type = 'parcels';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixedIdCodec()),
            Str::make('label'),
        ];
    }
}
