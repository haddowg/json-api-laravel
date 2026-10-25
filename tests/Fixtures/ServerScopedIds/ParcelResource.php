<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedIds;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `parcels` on the `default` server, with plain storage-key ids.
 */
final class ParcelResource extends AbstractResource
{
    public static string $type = 'parcels';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }
}
