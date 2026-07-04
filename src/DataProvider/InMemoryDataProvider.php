<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;

/**
 * An in-memory read provider: a test double and conformance witness. It reads from a
 * per-type {@see InMemoryStore} of domain objects keyed by id and answers `fetchOne()` /
 * `fetchCollection()` straight from it, so a slice runs with zero database.
 *
 * The window/count/count-free tail of a collection fetch runs through core's shared
 * {@see WindowExecutor} — the same tail the Eloquent reference provider runs — so the two
 * providers paginate identically and a spec test failing on one but not the other localizes
 * the bug to that provider's *execution*. That is what keeps this an attributable
 * conformance witness.
 *
 * It lives in `src/` (not `tests/`) so it is reusable as a documented worked example,
 * mirroring how core ships its `InMemory\Array{Filter,Sort}Handler`. One instance answers for
 * a single `$type`. To make a slice writable, pass an identifier closure and hand
 * {@see store()} to an {@see \haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister}; the
 * two then share one store, so writes are immediately readable.
 *
 * **Phase scope.** Filter/sort application (the shared criteria applier, core's array
 * filter/sort handlers) and the cursor (keyset) pagination arm land alongside the Eloquent
 * provider in a later phase; this build applies the pagination window only. The window tail
 * already runs through {@see WindowExecutor}, so it is byte-identical to a real provider's on
 * the offset/count-free/no-window paths from day one.
 *
 * @extends AbstractDataProvider<object>
 */
final class InMemoryDataProvider extends AbstractDataProvider
{
    private readonly InMemoryStore $store;

    private readonly WindowExecutor $windowExecutor;

    /**
     * @param iterable<int|string, object>          $items       objects keyed by id
     * @param (\Closure(object): string)|null       $identify    reads an item's id; required only if a
     *                                                            persister writes through {@see store()}
     * @param (\Closure(object, string): void)|null $assignId    writes a minted id onto an item; pass it to make
     *                                                            the shared store assign store-provided
     *                                                            (auto-increment) ids on an id-less create
     * @param ?InMemorySnapshotCoordinator          $coordinator coordinates a cross-store single-pass snapshot for
     *                                                            atomic rollback; pass the SAME instance to every
     *                                                            related store so a rollback preserves cross-store
     *                                                            object identity
     */
    public function __construct(
        private readonly string $type,
        iterable $items,
        ?\Closure $identify = null,
        ?\Closure $assignId = null,
        ?InMemorySnapshotCoordinator $coordinator = null,
    ) {
        $this->store = new InMemoryStore($items, $identify, $assignId, $coordinator);
        $this->windowExecutor = new WindowExecutor();
    }

    /**
     * The backing store, shared with an {@see \haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister}
     * to make this type writable.
     */
    public function store(): InMemoryStore
    {
        return $this->store;
    }

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->store->find($id);
    }

    /**
     * @return CollectionResult<object>
     */
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $items = $this->store->all();

        // Delegate the window/count/count-free tail to core's shared WindowExecutor over
        // array_slice/count closures — the same tail the Eloquent reference provider runs
        // (its closures push down LIMIT/OFFSET/COUNT). Count-free by default: a total is
        // computed only when the handler resolved a COUNT for this fetch ($criteria->wantsCount);
        // otherwise the executor probes one item past the window and reports `hasMore`.
        return $this->windowExecutor->run(
            $criteria->window,
            $criteria->wantsCount,
            all: static fn(): array => $items,
            count: static fn(): int => \count($items),
            page: static fn(int $offset, int $limit): array => \array_slice($items, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($items, $offset, $limit),
        );
    }
}
