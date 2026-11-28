<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;

/**
 * A pivot-aware provider's answer to a related-collection fetch: the page of related
 * members, already filtered, sorted and windowed over the pivot join, plus the pivot map
 * for exactly those members, `memberId => [pivotField => typed value]`, read by the same
 * query.
 *
 * It extends the core {@see CollectionResult}, so the pagination and render paths read
 * it as a plain result: items, total, windowed, hasMore. The handler renders the page
 * through a {@see \haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer} holding the map,
 * so each member carries its pivot values as `meta.pivot` with no separate read.
 *
 * @template-covariant TEntity of object
 *
 * @extends CollectionResult<TEntity>
 */
final class PivotCollectionResult extends CollectionResult
{
    /**
     * @param iterable<TEntity>                   $items    the page of related members
     * @param array<string, array<string, mixed>> $pivotMap `memberId => [field => typed value]`
     * @param ?int                                $total    the pre-window total, set when the page is windowed and counted
     * @param bool                                $windowed whether the fetch was windowed, read when the total is null to tell a count-free page from an unpaginated collection
     * @param bool                                $hasMore  for a count-free page, whether a further page follows
     */
    public function __construct(
        iterable $items,
        public readonly array $pivotMap,
        ?int $total = null,
        bool $windowed = false,
        bool $hasMore = false,
    ) {
        parent::__construct($items, $total, $windowed, $hasMore);
    }

    /**
     * The plain {@see CollectionResult} view of the page.
     *
     * @return CollectionResult<TEntity>
     */
    public function collection(): CollectionResult
    {
        return new CollectionResult($this->items, $this->total);
    }
}
