<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The **writable** `authors` resource: the target of the posts' owner-side to-ones and the
 * parent of the inverse-FK `posts` to-many — the HasMany mutation arm (an FK-move on the
 * Eloquent provider, a parent-list-set on the in-memory witness).
 */
#[AsJsonApiResource]
final class AuthorResource extends AbstractResource
{
    use DiscriminatesFeatureMember;

    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            HasMany::make('posts', 'posts'),
        ];
    }
}
