<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `tags` resource — a minimal far-member type for the join-table to-many mutations and a
 * polymorphic `feature` member.
 */
#[AsJsonApiResource]
final class TagResource extends AbstractResource
{
    use DiscriminatesFeatureMember;

    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label')->required(),
        ];
    }
}
