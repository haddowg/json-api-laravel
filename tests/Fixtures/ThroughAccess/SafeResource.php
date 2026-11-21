<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Safes, which only admins may read.
 */
#[AsJsonApiResource(readOnly: true, policy: SafePolicy::class)]
final class SafeResource extends AbstractResource
{
    public static string $type = 'safes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('code'),
        ];
    }
}
