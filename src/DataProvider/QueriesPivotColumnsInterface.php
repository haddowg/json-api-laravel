<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A provider that can filter and sort a `belongsToMany` related collection on its pivot
 * columns, and whose fetched members carry their own pivot row.
 *
 * When the related type's provider implements this and {@see queriesPivotColumns()} holds,
 * the related and relationship endpoints accept the relation's `pivot.`-columned filters and
 * its pivot field sorts, and read each member's `meta.pivot` off the fetched page through
 * {@see pivotMapOf()} instead of a whole-association read. A provider that does not
 * implement it (the in-memory witness) leaves pivot keys unrecognised (a `400`) and renders
 * `meta.pivot` from {@see DataProviderInterface::fetchRelationshipPivot()}, if it stores any.
 */
interface QueriesPivotColumnsInterface
{
    /**
     * Whether `$relation`, reached from `$parent`, is a pivot relation this provider queries
     * over its join: its `fetchRelatedCollection()` resolves `pivot.`-prefixed filter and sort
     * columns against the join table, and returns members carrying their pivot values.
     */
    public function queriesPivotColumns(object $parent, RelationInterface $relation): bool;

    /**
     * The pivot values of `$members` (a page this provider returned from
     * `fetchRelatedCollection()` for the same parent and relation), keyed by each member's
     * wire id: `relatedId => [pivotField => value]`. Reads what the fetch already loaded and
     * runs no query.
     *
     * @param list<object> $members
     *
     * @return array<string, array<string, mixed>>
     */
    public function pivotMapOf(object $parent, RelationInterface $relation, array $members): array;
}
