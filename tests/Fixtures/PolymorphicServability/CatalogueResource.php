<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PolymorphicServability;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A host type carrying a polymorphic to-one whose candidates are the discriminating
 * {@see GoodMemberResource} and the non-discriminating {@see FlawedMemberResource} — so the
 * servability warmer's polymorphic guard reports the flawed candidate.
 */
#[AsJsonApiResource(readOnly: true)]
final class CatalogueResource extends AbstractResource
{
    public static string $type = 'catalogues';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            MorphTo::make('feature', ['good_members', 'flawed_members']),
        ];
    }
}
