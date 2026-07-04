<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortArmInterface;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApiLaravel\DataProvider\Keyset\CursorTokenMinter;
use haddowg\JsonApiLaravel\DataProvider\Keyset\InMemoryKeyset;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetColumn;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetResolver;

/**
 * An in-memory read provider: a test double and conformance witness. It reads from a
 * per-type {@see InMemoryStore} of domain objects keyed by id and answers `fetchOne()` /
 * `fetchCollection()` straight from it, so a slice runs with zero database.
 *
 * Collections run the same {@see CriteriaApplier} matching as the Eloquent reference
 * provider, executed through core's reference in-memory handlers
 * ({@see ArrayFilterHandler} / {@see ArraySortHandler}) with an `array_slice` for the
 * pagination window — so a spec test failing on one provider but not the other localizes
 * the bug to that provider's *execution*. The cursor (keyset) arm runs core's shared
 * keyset machinery ({@see KeysetResolver} + {@see InMemoryKeyset} + {@see CursorTokenMinter}),
 * the ground truth the Eloquent SQL push-down must match byte-for-byte. That is what keeps
 * this an attributable conformance witness.
 *
 * It lives in `src/` (not `tests/`) so it is reusable as a documented worked example,
 * mirroring how core ships its `InMemory\Array{Filter,Sort}Handler`. One instance answers for
 * a single `$type`. To make a slice writable, pass an identifier closure and hand
 * {@see store()} to an {@see \haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister}; the
 * two then share one store, so writes are immediately readable.
 *
 * **Phase scope.** The related / count / to-one-match / pivot relationship seams are Phase 3;
 * for now they resolve through the neutral {@see AbstractDataProvider} defaults (the caller
 * treats each as "this capability is absent"). This build serves the full primary-collection
 * read surface — filters, sorts, the offset/count-free/no-window arms, and the cursor arm.
 *
 * @extends AbstractDataProvider<object>
 */
final class InMemoryDataProvider extends AbstractDataProvider
{
    private readonly InMemoryStore $store;

    private readonly CriteriaApplier $applier;

    private readonly WindowExecutor $windowExecutor;

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    private readonly KeysetResolver $keysetResolver;

    private readonly InMemoryKeyset $keyset;

    private readonly CursorTokenMinter $minter;

    /**
     * @param iterable<int|string, object>          $items       objects keyed by id
     * @param (\Closure(object): string)|null       $identify    reads an item's id; required only if a
     *                                                            persister writes through {@see store()}
     * @param (\Closure(object, string): void)|null $assignId    writes a minted id onto an item; pass it to make
     *                                                            the shared store assign store-provided
     *                                                            (auto-increment) ids on an id-less create
     * @param string                                $idColumn    the model member the cursor (keyset) page reads as the
     *                                                            primary-key tiebreaker (via core's `Accessor`); defaults to `id`
     * @param iterable<ArrayFilterArmInterface>     $filterArms  author arms for custom `FilterInterface` types (this provider
     *                                                            is hand-constructed, so arms are passed here rather than tagged)
     * @param iterable<ArraySortArmInterface>       $sortArms    author arms for custom `SortInterface` types
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
        private readonly string $idColumn = 'id',
        iterable $filterArms = [],
        iterable $sortArms = [],
        ?InMemorySnapshotCoordinator $coordinator = null,
    ) {
        $this->store = new InMemoryStore($items, $identify, $assignId, $coordinator);
        $this->applier = new CriteriaApplier();
        $this->windowExecutor = new WindowExecutor();
        $this->filterHandler = new ArrayFilterHandler($filterArms);
        $this->sortHandler = new ArraySortHandler($sortArms);
        $this->keysetResolver = new KeysetResolver();
        $this->keyset = new InMemoryKeyset();
        $this->minter = new CursorTokenMinter(new CursorCodec());
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
        // A cursor (keyset) window is its own execution: the keyset builds its OWN
        // NULL=largest order and the strictly-after predicate, so the plain sort
        // handler is bypassed (it omits the null-forcing + the PK tiebreak). The
        // filters still apply (and `?sort` is still validated against the
        // vocabulary by the keyset resolver); an OffsetWindow / null window stays
        // on the shared executor tail, byte-identical to before.
        if ($criteria->window instanceof CursorWindow) {
            return $this->runCursor($criteria, $this->store->all());
        }

        // Count-free by default (G21): count the pre-window total only when the
        // handler resolved a COUNT for this fetch (the paginator's withCount()
        // author opt-in, or ?withCount=_self_ under a countable() resource);
        // otherwise the executor fetches count-free via the window+1 probe and
        // reports `hasMore`.
        return $this->applyAndWindow($criteria, $this->store->all(), countable: $criteria->wantsCount);
    }

    /**
     * The in-memory cursor (keyset) execution — the **ground truth** the Eloquent
     * push-down matches byte-for-byte (bundle ADR 0063). It resolves the keyset
     * columns (the active sort + the appended/deduped PK; validates `?sort`),
     * applies the filters, checks the cursor against the resolved columns (a stale
     * cursor → 400), then runs {@see InMemoryKeyset}: sort by the forced
     * NULL=largest order, keep the rows strictly after the boundary, over-fetch
     * `limit + 1`, slice, and (for a backward page) flip the directions and
     * reverse. Tokens are minted off the sliced page via the shared
     * {@see CursorTokenMinter}.
     *
     * @param list<object> $items
     *
     * @return CursorCollectionResult<object>
     */
    private function runCursor(CollectionCriteria $criteria, array $items): CursorCollectionResult
    {
        $window = $criteria->window;
        \assert($window instanceof CursorWindow);

        // Resolve the keyset columns (the active sort + the PK), validating `?sort`
        // against the vocabulary exactly as the plain path does. The PK direction
        // for a PK-only keyset follows the resource default-sort-on-PK; with none
        // it is ascending (the resolver's default).
        $columns = $this->keysetResolver->resolve($criteria, $this->idColumn);

        // Apply the FILTERS only — the keyset owns the order, so the plain sort is
        // never applied (a sort-stripped criteria leaves the filter application
        // untouched and adds no ordering).
        $items = $this->applyFiltersOnly($criteria, $items);

        // page[before] wins over page[after]: a backward page flips the column
        // directions (which, under NULL=largest, flips the null bucket too) and
        // the after-predicate, so "strictly after under the reversed order" means
        // "strictly before under the natural order".
        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward ? $this->flip($columns) : $columns;

        if ($boundary !== null) {
            $parameter = $backward ? 'page[before]' : 'page[after]';
            $this->keysetResolver->assertFresh($boundary, $columns, $parameter);
        }

        $sorted = $this->keyset->sort($items, $orderColumns);
        if ($boundary !== null) {
            $sorted = $this->keyset->after($sorted, $boundary, $orderColumns);
        }

        // Over-fetch by one: the surplus proves a further page (forward → next,
        // backward → prev). Slice to the limit, then re-orient a backward page to
        // natural forward order for rendering.
        $hasSurplus = \count($sorted) > $window->limit;
        $page = \array_slice($sorted, 0, $window->limit);
        if ($backward) {
            $page = \array_reverse($page);
        }

        return $this->minter->mint(
            $window,
            $columns,
            \array_values($page),
            $hasSurplus,
            static fn(object $row, string $column): string|int|float|bool|null => CursorTokenMinter::coerce(Accessor::get($row, $column)),
        );
    }

    /**
     * Applies the criteria's FILTERS to `$items` (never the sort — the keyset owns
     * the order). A sort-stripped, window-less criteria reuses the shared
     * {@see CriteriaApplier} so the filter semantics are identical to a plain
     * fetch; the empty sort + default sort means the applier adds no ordering.
     *
     * @param list<object> $items
     *
     * @return list<object>
     */
    private function applyFiltersOnly(CollectionCriteria $criteria, array $items): array
    {
        $filterOnly = new CollectionCriteria(
            new QueryParameters(
                $criteria->queryParameters->fields,
                $criteria->queryParameters->includes,
                sort: [],
                filter: $criteria->queryParameters->filter,
                pagination: $criteria->queryParameters->pagination,
            ),
            $criteria->filters,
            sorts: [],
            window: null,
            defaultSort: [],
        );

        /** @var list<object> $applied */
        $applied = $this->applier->apply($filterOnly, $items, $this->filterHandler, $this->sortHandler);

        return $applied;
    }

    /**
     * The keyset columns with every direction flipped — the backward-page order
     * (which, under NULL=largest, also flips the null-bucket placement because the
     * comparator reads the per-column direction directly).
     *
     * @param list<KeysetColumn> $columns
     *
     * @return list<KeysetColumn>
     */
    private function flip(array $columns): array
    {
        return \array_map(
            static fn(KeysetColumn $column): KeysetColumn => new KeysetColumn($column->column, !$column->descending),
            $columns,
        );
    }

    /**
     * Applies `$criteria` (filter + sort) to `$items` through core's reference
     * in-memory handlers, then delegates the window/count/count-free tail to the
     * shared {@see WindowExecutor} (core ADR 0061) over `array_slice`/`count`
     * closures — the same tail the Eloquent reference provider runs (its closures
     * push down LIMIT/OFFSET/COUNT).
     *
     * When `$countable` is false (the count-free default, bundle ADR 0075) the
     * executor builds the window **count-free**: the result carries no total, only
     * a `hasMore` flag derived from a limit+1 probe — so the handler renders a
     * count-free page (no `total`/`last`). A counted fetch passes `$countable` true
     * and carries the pre-window total. The in-memory `count(items) > offset +
     * count(page)` form is equivalent to the executor's limit+1 probe.
     *
     * @param list<mixed> $items
     *
     * @return CollectionResult<object>
     */
    private function applyAndWindow(CollectionCriteria $criteria, array $items, bool $countable): CollectionResult
    {
        /** @var list<object> $items */
        $items = $this->applier->apply(
            $criteria,
            $items,
            $this->filterHandler,
            $this->sortHandler,
        );

        return $this->windowExecutor->run(
            $criteria->window,
            $countable,
            all: static fn(): array => $items,
            count: static fn(): int => \count($items),
            page: static fn(int $offset, int $limit): array => \array_slice($items, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($items, $offset, $limit),
        );
    }
}
