<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Vaults, which only admins may list.
 */
#[AsJsonApiResource(readOnly: true, policy: VaultPolicy::class)]
final class VaultResource extends AbstractResource
{
    public static string $type = 'vaults';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('code'),
        ];
    }
}
