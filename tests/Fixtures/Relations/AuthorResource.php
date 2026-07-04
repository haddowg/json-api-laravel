<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Relations;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `authors` resource — a leaf type and one member of a {@see Post}'s polymorphic
 * relations. Its object-aware {@see DiscriminatesBlogMember::getType()} lets the morph
 * resolver pick it for an {@see Author} member.
 */
#[AsJsonApiResource(readOnly: true)]
final class AuthorResource extends AbstractResource
{
    use DiscriminatesBlogMember;

    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
