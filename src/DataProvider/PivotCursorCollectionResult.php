<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CursorCollectionResult;

/**
 * A pivot-aware provider's answer to a cursor (keyset) related-collection fetch: the page
 * of related members and the boundary cursors the provider minted for them, plus the pivot
 * map for exactly those members, `memberId => [pivotField => typed value]`, read by the
 * same query, as {@see PivotCollectionResult} carries it for an offset page.
 *
 * Count-free unless the request asked for the total with `?withCount=_self_`; then
 * {@see $total} is the size of the filtered collection.
 *
 * @template-covariant TEntity of object
 *
 * @extends CursorCollectionResult<TEntity>
 */
final class PivotCursorCollectionResult extends CursorCollectionResult
{
    /**
     * @param iterable<TEntity>                   $items        the page of related members
     * @param array<string, array<string, mixed>> $pivotMap     `memberId => [field => typed value]`
     * @param ?string                             $cursorBefore the encoded `prev` boundary, or null when the page is empty
     * @param ?string                             $cursorAfter  the encoded `next` boundary, or null when the page is empty
     * @param bool                                $hasPrevious  whether a previous page exists
     * @param bool                                $hasMore      whether a following page exists
     * @param ?int                                $total        the size of the filtered collection when the request counts it, otherwise null
     */
    public function __construct(
        iterable $items,
        public readonly array $pivotMap,
        ?string $cursorBefore = null,
        ?string $cursorAfter = null,
        bool $hasPrevious = false,
        bool $hasMore = false,
        ?int $total = null,
    ) {
        parent::__construct($items, $cursorBefore, $cursorAfter, $hasPrevious, $hasMore, $total);
    }
}
