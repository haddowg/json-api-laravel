<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;

/**
 * The result of a batched related-collection fetch: one relation's windowed page for
 * **every parent in a page**, keyed by the parent's JSON:API (wire) id — the
 * collection/include twin of a single {@see CollectionResult}, the read-side analogue of
 * the count-only map {@see DataProviderInterface::countRelated()} returns.
 *
 * {@see DataProviderInterface::fetchRelatedCollectionBatch()} produces it for ONE relation
 * across the whole page of parents in a single store round-trip, so a collection include
 * that windows N relations over M parents costs O(N) statements, not O(M*N) — the batch
 * retires the per-parent `fetchRelatedCollection` loop. The Eloquent provider partitions a
 * flat IN-scoped result by parent in PHP and windows each partition through the shared
 * {@see \haddowg\JsonApi\Collection\WindowExecutor}.
 *
 * Keyed by the parent **wire** id (the value the serializer renders / the store identifies
 * a parent by), exactly as {@see DataProviderInterface::countRelated()} keys its map, so a
 * caller reconciles a result back to its parent object through the same wire-id resolution.
 * A provider holds an entry for every parent it loaded, an empty result for one with no
 * related members. A parent with no entry was not loaded ({@see has()}), so the caller reads
 * that parent's relation another way instead of treating it as empty.
 */
final readonly class RelatedBatch
{
    /**
     * @param array<int|string, CollectionResult<object>> $resultsByParentWireId the per-parent windowed page,
     *                                                                            keyed by parent wire id (an
     *                                                                            integer-PK wire id is a numeric
     *                                                                            string PHP stores as an int array
     *                                                                            key; {@see for()} reconciles the
     *                                                                            lookup against a string wire id)
     */
    public function __construct(
        private array $resultsByParentWireId,
    ) {}

    /**
     * Whether the batch loaded the parent with `$parentWireId`. An empty batch, such as
     * {@see AbstractDataProvider}'s default, loaded no parent at all.
     */
    public function has(string $parentWireId): bool
    {
        return \array_key_exists($parentWireId, $this->resultsByParentWireId);
    }

    /**
     * The windowed page for the parent with `$parentWireId`, or an EMPTY
     * {@see CollectionResult} when the batch holds no entry for the parent ({@see has()}). The
     * empty result carries no window metadata (no total, not windowed, no further page).
     *
     * @return CollectionResult<object>
     */
    public function for(string $parentWireId): CollectionResult
    {
        return $this->resultsByParentWireId[$parentWireId] ?? new CollectionResult([]);
    }
}
