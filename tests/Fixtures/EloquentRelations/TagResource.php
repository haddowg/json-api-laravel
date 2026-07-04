<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `tags` resource for the Eloquent blog fixture — a leaf type, the target of a
 * {@see Post}'s `tags` belongsToMany and the other member of its polymorphic relations. Its
 * object-aware {@see DiscriminatesBlogMember::getType()} lets the morph resolver pick it for
 * a {@see Tag} model.
 */
#[AsJsonApiResource(readOnly: true)]
final class TagResource extends AbstractResource
{
    use DiscriminatesBlogMember;

    public static string $type = 'tags';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }
}
