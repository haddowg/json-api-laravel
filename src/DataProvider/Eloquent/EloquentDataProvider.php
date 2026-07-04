<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorCodec;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\CriteriaApplier;
use haddowg\JsonApiLaravel\DataProvider\Keyset\CursorTokenMinter;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetColumn;
use haddowg\JsonApiLaravel\DataProvider\Keyset\KeysetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The reference Eloquent read provider (PLAN decision 2): the storage twin of the
 * in-memory witness, executing the SAME {@see CriteriaApplier} matching against a
 * real {@see Builder} so a spec test failing on one provider but not the other
 * localizes the bug to that provider's *execution*.
 *
 * One instance serves every Eloquent-mapped type — constructed with a
 * `type → model class-string` map (the analogue of the Doctrine provider's
 * `entityClassByType`) — so `TEntity` cannot narrow past `object`. It registers at
 * the lowest priority (`-128`) so an application provider at the default priority
 * shadows it for the types it serves.
 *
 * A collection fetch is one `Builder` pipeline: the shared {@see CriteriaApplier}
 * matches the requested `filter[…]`/`sort` parameters against the declared
 * vocabularies and pushes each down through the {@see EloquentFilterHandler} /
 * {@see EloquentSortHandler}; the window/count/count-free tail then runs through
 * the shared {@see WindowExecutor} over `offset`/`limit`/`count` closures — items
 * are never over-fetched (a countable page counts then fetches; a count-free page
 * probes `limit + 1`). A cursor (keyset) window is its own pushed-down execution
 * ({@see EloquentKeyset}: the forced NULL=largest `ORDER BY` + the keyset `WHERE`),
 * resolving its columns from the ONE {@see KeysetResolver} the in-memory witness
 * uses, so SQL vs PHP windowing cannot drift.
 *
 * **Phase scope.** The related / count / to-one-match / pivot relationship seams
 * are Phase 3; for now they resolve through the neutral {@see AbstractDataProvider}
 * defaults. `?include` batching, `?withCount`, and the windowed relation batch are
 * later phases.
 *
 * **Wiring (Phase 1).** The `type → model` map is constructed by hand and registered
 * at `-128` (see the workbench provider). ADR 0002's zero-config promise — a `model:`
 * on `#[AsJsonApiResource]` accumulated into one auto-registered `-128` provider, the
 * Laravel twin of the bundle's `DoctrineEntityMapPass` — is **deferred to Phase 2**,
 * alongside the persister half that completes the reference pair (ADR 0002,
 * "Deferred: attribute-driven auto-registration").
 *
 * @extends AbstractDataProvider<object>
 */
final class EloquentDataProvider extends AbstractDataProvider
{
    private readonly CriteriaApplier $applier;

    private readonly WindowExecutor $windowExecutor;

    private readonly EloquentFilterHandler $filterHandler;

    private readonly EloquentSortHandler $sortHandler;

    private readonly KeysetResolver $keysetResolver;

    private readonly CursorTokenMinter $minter;

    /**
     * @var array<string, class-string<Model>>
     */
    private readonly array $modelByType;

    /**
     * @param array<string, class-string<Model>>          $modelByType a `type → Eloquent model FQCN` map
     * @param iterable<EloquentFilterArmInterface<Model>> $filterArms  author arms for custom `FilterInterface` types
     * @param iterable<EloquentSortArmInterface<Model>>   $sortArms    author arms for custom `SortInterface` types
     */
    public function __construct(array $modelByType, iterable $filterArms = [], iterable $sortArms = [])
    {
        $this->modelByType = $modelByType;
        $this->applier = new CriteriaApplier();
        $this->windowExecutor = new WindowExecutor();
        $this->filterHandler = new EloquentFilterHandler($filterArms);
        $this->sortHandler = new EloquentSortHandler($sortArms);
        $this->keysetResolver = new KeysetResolver();
        $this->minter = new CursorTokenMinter(new CursorCodec());
    }

    public function supports(string $type): bool
    {
        return isset($this->modelByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->newQuery($type)->whereKey($id)->first();
    }

    /**
     * @return CollectionResult<object>
     */
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $builder = $this->newQuery($type);

        // A cursor (keyset) window is its own pushed-down execution (the keyset
        // WHERE + the forced NULL=largest ORDER BY); the OffsetWindow / null-window
        // path stays byte-identical below. The keyset still applies the FILTERS via
        // the shared applier (and validates `?sort` through the resolver) but builds
        // its OWN order, never the plain sort handler (bundle ADR 0063).
        if ($criteria->window instanceof CursorWindow) {
            return $this->runCursor($builder, $criteria, $criteria->window);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        // Count-free by default (G21): the executor counts the pre-window total and
        // fetches the windowed page only when the handler resolved a COUNT for this
        // fetch (the paginator's withCount() author opt-in, or ?withCount=_self_
        // under a countable() resource); otherwise it fetches count-free via the
        // window+1 probe (no COUNT) and reports `hasMore` (bundle ADR 0075).
        return $this->windowExecutor->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: static fn(): array => \array_values($builder->get()->all()),
            count: static fn(): int => (clone $builder)->reorder()->count(),
            page: static fn(int $offset, int $limit): array => \array_values(
                (clone $builder)->offset($offset)->limit($limit)->get()->all(),
            ),
            probe: static fn(int $offset, int $limit): array => \array_values(
                (clone $builder)->offset($offset)->limit($limit)->get()->all(),
            ),
        );
    }

    /**
     * The cursor (keyset) execution pushed down to SQL — the twin of the in-memory
     * witness ({@see \haddowg\JsonApiLaravel\DataProvider\Keyset\InMemoryKeyset}),
     * the ground truth (bundle ADR 0063). It resolves the keyset columns (the active
     * sort + the appended/deduped PK; validates `?sort`), applies the filters, checks
     * the cursor against the resolved columns (a stale cursor → 400), then via
     * {@see EloquentKeyset} builds the forced NULL=largest `ORDER BY` and the
     * IS-NULL-branched keyset `WHERE`, over-fetching `limit + 1` through the shared
     * {@see WindowExecutor::runCursor()}. A backward (`page[before]`) page flips every
     * direction and the after-predicate, then reverses the sliced rows to natural
     * forward order before minting.
     *
     * @param Builder<Model> $builder
     *
     * @return CursorCollectionResult<object>
     */
    private function runCursor(Builder $builder, CollectionCriteria $criteria, CursorWindow $window): CursorCollectionResult
    {
        $model = $builder->getModel();
        $pkColumn = $model->getKeyName();

        $columns = $this->keysetResolver->resolve($criteria, $pkColumn);

        // Apply the FILTERS only (the keyset owns the order). A sort-stripped,
        // window-less criteria reuses the shared applier so the filter semantics are
        // identical to a plain fetch, and the empty sort adds no ORDER BY.
        $builder = $this->applier->apply($this->filtersOnly($criteria), $builder, $this->filterHandler, $this->sortHandler);

        // page[before] wins over page[after]: a backward page flips the directions
        // (incl. the null bucket) and the after-predicate, so "after under the
        // reversed order" is "before under the natural order".
        $backward = $window->before !== null;
        $boundary = $backward ? $window->before : $window->after;
        $orderColumns = $backward ? $this->flip($columns) : $columns;

        if ($boundary !== null) {
            $parameter = $backward ? 'page[before]' : 'page[after]';
            $this->keysetResolver->assertFresh($boundary, $columns, $parameter);
        }

        $keyset = new EloquentKeyset($model);
        if ($boundary !== null) {
            $keyset->applyAfter($builder, $boundary, $orderColumns);
        }
        $keyset->orderBy($builder, $orderColumns);

        return $this->windowExecutor->runCursor(
            $window,
            // Over-fetch limit+1 in the (possibly flipped) order; the surplus is
            // dropped by runCursor BEFORE the cursors closure mints.
            probe: static fn(CursorWindow $w): array => \array_values((clone $builder)->limit($w->limit + 1)->get()->all()),
            cursors: function (array $rows, bool $hasMore) use ($window, $columns, $backward): CursorCollectionResult {
                // Re-orient a backward page to natural forward order for rendering.
                $page = $backward ? \array_reverse($rows) : $rows;

                return $this->minter->mint(
                    $window,
                    $columns,
                    \array_values($page),
                    $hasMore,
                    static fn(object $row, string $column): string|int|float|bool|null => CursorTokenMinter::coerce(
                        $row instanceof Model ? $row->getAttribute($column) : null,
                    ),
                );
            },
        );
    }

    /**
     * A sort-stripped, window-less copy of `$criteria` so the shared applier applies
     * only its FILTERS on the cursor path (the keyset owns the order).
     */
    private function filtersOnly(CollectionCriteria $criteria): CollectionCriteria
    {
        return new CollectionCriteria(
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
            aliasOf: $criteria->aliasOf,
        );
    }

    /**
     * The keyset columns with every direction flipped — the backward-page order
     * (which, under NULL=largest, also flips the null-bucket placement).
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
     * A fresh root query for the type's model.
     *
     * @return Builder<Model>
     */
    private function newQuery(string $type): Builder
    {
        $class = $this->modelByType[$type]
            ?? throw new \LogicException(\sprintf('No Eloquent model is mapped for JSON:API type "%s".', $type));

        $model = new $class();
        \assert($model instanceof Model);

        return $model->newQuery();
    }
}
