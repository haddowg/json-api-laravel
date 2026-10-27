<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Scopes;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A shelf that includes its book by default, guarded by a policy with a `before()`.
 */
#[AsJsonApiResource(readOnly: true, policy: ShelfPolicy::class)]
final class ShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('book', 'books'),
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['book'];
    }
}
