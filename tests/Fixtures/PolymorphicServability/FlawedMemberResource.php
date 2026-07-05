<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PolymorphicServability;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A polymorphic-relation candidate that does NOT override `getType()`, so it returns its
 * static `$type` for every object and would silently claim (and mis-serialize) members of its
 * sibling type — the fixture the servability warmer's polymorphic-discrimination guard must
 * flag at deploy.
 */
#[AsJsonApiResource(readOnly: true)]
final class FlawedMemberResource extends AbstractResource
{
    public static string $type = 'flawed_members';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }
}
