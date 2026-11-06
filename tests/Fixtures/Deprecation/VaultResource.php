<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Deprecation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A type deprecated on a date, with a sunset.
 *
 * @internal
 */
#[AsJsonApiResource(
    readOnly: true,
    deprecation: 'Mon, 01 Jun 2026 00:00:00 GMT',
    sunset: 'Sat, 01 Jan 2028 00:00:00 GMT',
)]
final class VaultResource extends AbstractResource
{
    public static string $type = 'vaults';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
