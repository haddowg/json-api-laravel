<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A provider that could filter and sort a `belongsToMany` related collection on its pivot
 * columns, and whose fetched members carried their own pivot row.
 *
 * The package no longer consults it. A provider queries pivot rows through
 * {@see PivotAwareProviderInterface::supportsPivot()} and
 * {@see PivotAwareProviderInterface::fetchRelatedPivotCollection()}, which returns the page
 * with its pivot map, as the Symfony bundle's providers do.
 *
 * @deprecated implement {@see PivotAwareProviderInterface} instead; this interface is removed in the next major
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
