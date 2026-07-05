<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PolymorphicServability;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A polymorphic-relation candidate that DOES discriminate by class (overrides `getType()`),
 * so the guard never flags it — the well-formed sibling of {@see FlawedMemberResource}.
 */
#[AsJsonApiResource(readOnly: true)]
final class GoodMemberResource extends AbstractResource
{
    public static string $type = 'good_members';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }

    public function getType(mixed $object): string
    {
        return 'good_members';
    }
}
