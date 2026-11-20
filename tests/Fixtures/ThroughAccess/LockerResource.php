<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Parcels served again as lockers, whose ids are encoded, so a stored key and its wire id
 * differ. Each caller sees only their own, as with parcels.
 */
#[AsJsonApiResource(readOnly: true, policy: OpenPolicy::class)]
final class LockerResource extends AbstractResource
{
    public static string $type = 'lockers';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new LockerIdEncoder()),
            Str::make('owner_id'),
            Str::make('label'),
        ];
    }
}
