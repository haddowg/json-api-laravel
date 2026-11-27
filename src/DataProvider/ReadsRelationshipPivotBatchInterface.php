<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A provider that reads the stored pivot values of a `belongsToMany` for many parents at
 * once. An included pivot relation whose members carry no pivot row of their own renders its
 * stored fields from this map, read once for every parent of the type the document holds.
 * A provider that stores pivot values and doesn't implement it renders only the computed
 * fields of an included pivot relation.
 */
interface ReadsRelationshipPivotBatchInterface
{
    /**
     * The stored pivot values of `$relation` for each of `$parents`, of type `$type`, keyed
     * by the parent's wire id then the member's wire id. A parent with no members may be
     * left out.
     *
     * @param list<object> $parents
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function fetchRelationshipPivotBatch(string $type, array $parents, RelationInterface $relation): array;
}
