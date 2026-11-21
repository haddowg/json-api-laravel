<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The storage-agnostic half of a collection fetch: matches the requested
 * `filter[…]` keys and `sort` fields against the declared vocabularies in a
 * {@see CollectionCriteria} and applies each match to a provider-native query
 * through the provider's core filter/sort handlers, threading the query value
 * through (`TQuery` is an Eloquent `Builder` for the Eloquent provider, a `list`
 * in memory).
 *
 * Every provider runs this same matching, so the spec semantics — declared
 * filter defaults folded into the requested map (absent key → the filter's
 * declared default, a requested key always wins) via core's
 * {@see FilterDefaults}, unknown filter key → 400 {@see FilterParamUnrecognized},
 * sorting against an empty sort vocabulary → 400 {@see SortingUnsupported},
 * unknown sort field → 400 {@see SortParamUnrecognized}, `-` prefix →
 * descending — are decided once, and a provider only ever differs in
 * *execution*. That is what keeps the in-memory provider an attributable
 * conformance witness for the Eloquent one. Because defaults are folded before
 * the filters reach a handler, they also narrow the pre-window `COUNT` of a
 * paginated fetch, so totals describe the defaulted collection.
 *
 * **Scope.** The bundle's applier is additionally *alias-aware* — it can
 * push a directive down onto a non-root query alias (the {@see CollectionCriteria::$aliasOf}
 * map, populated only on the pivot related-collection path). That push-down is a
 * relationships/pivot concern handled by the Eloquent alias-aware
 * handlers; here every directive routes to the query root, exactly as the
 * bundle's applier does on every non-pivot fetch path and the entire in-memory
 * provider. Sorts do not compose commutatively, so the whole
 * ordered directive list is handed to the sort handler in ONE composite call,
 * most-significant first.
 */
final class CriteriaApplier
{
    /**
     * Applies the criteria's requested filters and sorts to `$query` and
     * returns the modified query. Pagination windowing is **not** applied here —
     * how a window executes (LIMIT/OFFSET vs `array_slice`) is the provider's.
     *
     * The handler parameters are contravariant in `TQuery`: a handler declared
     * for a broader query type (core's in-memory handlers operate on
     * `list<mixed>`) is a valid executor for any narrower query.
     *
     * @template TQuery
     *
     * @param TQuery                                       $query
     * @param FilterHandlerInterface<contravariant TQuery> $filterHandler
     * @param SortHandlerInterface<contravariant TQuery>   $sortHandler
     *
     * @return TQuery
     *
     * @throws FilterParamUnrecognized when a requested filter key is not declared
     * @throws FilterValueInvalid      when a list filter's value is not a list it accepts
     * @throws SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws SortParamUnrecognized   when a requested sort field is not declared
     */
    public function apply(
        CollectionCriteria $criteria,
        mixed $query,
        FilterHandlerInterface $filterHandler,
        SortHandlerInterface $sortHandler,
    ): mixed {
        $query = $this->applyFilters($criteria, $query, $filterHandler);

        return $this->applySorts($criteria, $query, $sortHandler);
    }

    /**
     * Applies only the criteria's requested filters, with the declared defaults folded in,
     * and never its sorts. A collection action's filtered query starts here.
     *
     * @template TQuery
     *
     * @param TQuery                                       $query
     * @param FilterHandlerInterface<contravariant TQuery> $handler
     *
     * @return TQuery
     *
     * @throws FilterParamUnrecognized when a requested filter key is not declared
     * @throws FilterValueInvalid      when a list filter's value is not a list it accepts
     */
    public function applyFilters(CollectionCriteria $criteria, mixed $query, FilterHandlerInterface $handler): mixed
    {
        // Fold each declared filter's default into the requested map first: an
        // absent key takes its filter's declared default, a requested key wins.
        // A defaulted key is always declared (the default came from a declared
        // filter), so the matcher's unrecognised-key 400 only ever fires for a
        // genuinely undeclared requested key. The matcher resolves filter families and
        // reads a list filter's value as its members. Each match then resolves its traversal
        // path, so a family's filter resolves per request too.
        $requested = FilterDefaults::apply($criteria->queryParameters->filter, $criteria->filters);

        $type = $criteria->paths?->type;
        if ($type !== null && $handler instanceof TargetsTypeInterface) {
            $handler = $handler->forType($type, $criteria->paths->named);
        }

        foreach (FilterMatcher::directives($requested, $criteria->filters, $criteria->request) as $directive) {
            $filter = $criteria->paths?->resolve($directive->filter) ?? $directive->filter;
            $paths = $criteria->paths === null ? null : $this->readablePaths($criteria, $criteria->paths, $filter, $directive->key(), $directive->value);
            $applying = $paths !== null && $handler instanceof ScopesFilterHopsInterface ? $handler->forPaths($paths) : $handler;
            $query = $applying->apply($filter, $query, $directive->value);
        }

        return $query;
    }

    /**
     * The paths `$filter`, matched under `$key`, runs with: the relations it reaches through
     * that the caller may not read. A filter the client asked for is refused instead, with the
     * `403` a related read of the relation gets. A filter default isn't the client's, so it
     * matches no row through such a relation.
     *
     * @throws AuthorizationException when the client's filter reaches through a relation the caller may not read
     */
    private function readablePaths(CollectionCriteria $criteria, FilterPaths $paths, FilterInterface $filter, string $key, mixed $value): FilterPaths
    {
        $request = $criteria->request;
        $refused = $request === null ? [] : $paths->refusedHops($paths->hopsOfRequested($filter, $value), $request);
        if ($refused !== [] && \array_key_exists($key, $criteria->queryParameters->filter)) {
            throw new AuthorizationException(\sprintf('Access denied to the relation "%s" the filter "%s" reaches through.', $refused[0]->relation->name(), $key));
        }

        return $paths->scoped(\array_map(static fn(RelationHop $hop): RelationInterface => $hop->relation, $refused));
    }

    /**
     * Applies the requested sorts, or the default order when none is requested, each sort
     * reaching through relations resolved against the type the criteria run against and
     * gated as {@see readableSortPaths()} decides.
     *
     * @template TQuery
     *
     * @param TQuery                                     $query
     * @param SortHandlerInterface<contravariant TQuery> $handler
     *
     * @return TQuery
     *
     * @throws SortingUnsupported     when sorting is requested but no sorts are declared
     * @throws SortParamUnrecognized  when a requested or default sort names an undeclared sort
     * @throws AuthorizationException when the client's sort reaches through a relation the caller may not read
     */
    private function applySorts(CollectionCriteria $criteria, mixed $query, SortHandlerInterface $handler): mixed
    {
        $requested = $criteria->queryParameters->sort;

        // No `?sort`: fall back to the resource's default order (if any). An
        // explicit `?sort=` overrides the default entirely — the default is never
        // appended to a requested sort (core's docs/sorts.md). The defaults are still
        // matched against the declared sort vocabulary (same as a requested sort),
        // so a default naming an undeclared sort is a server-config error rather
        // than a silently dropped directive. Requested sorts match in request order
        // (exact keys, then sort families); the handler owns the combination (sorts do
        // not compose commutatively), so the whole list is applied in ONE composite
        // call, most significant first.
        $directives = $requested === []
            ? $this->defaultDirectives($criteria)
            : SortMatcher::directives($requested, $criteria->sorts, $criteria->request);
        if ($directives === []) {
            return $query;
        }

        $directives = \array_map(static fn(SortDirective $directive): SortDirective => self::resolvedDirective($criteria, $directive), $directives);
        if ($criteria->paths !== null) {
            $paths = $this->readableSortPaths($criteria, $criteria->paths, $directives);
            if ($handler instanceof ScopesFilterHopsInterface) {
                $handler = $handler->forPaths($paths);
            }
        }

        return $handler->apply($directives, $query);
    }

    /**
     * The paths sorts reaching through relations run with, as {@see readablePaths()} decides
     * for a filter: a sort the client asked for through a relation the caller may not read is
     * refused with the `403` a related read of the relation gets, and a default order reads
     * `null` through such a relation, as if every row there were hidden.
     *
     * @param list<SortDirective> $directives
     *
     * @throws AuthorizationException when the client's sort reaches through a relation the caller may not read
     */
    private function readableSortPaths(CollectionCriteria $criteria, FilterPaths $paths, array $directives): FilterPaths
    {
        $request = $criteria->request;
        $requested = $criteria->queryParameters->sort !== [];
        $refused = [];
        foreach ($directives as $directive) {
            $hops = $request === null ? [] : $paths->refusedHops($paths->hopsOfSort($directive->sort), $request);
            if ($hops !== [] && $requested) {
                throw new AuthorizationException(\sprintf('Access denied to the relation "%s" the sort "%s" reaches through.', $hops[0]->relation->name(), $directive->sort->key()));
            }
            foreach ($hops as $hop) {
                $refused[] = $hop->relation;
            }
        }

        return $paths->scoped($refused);
    }

    /**
     * The keyset columns of a cursor page with each sort reaching through relations resolved
     * against the type the criteria run against, and the paths its levels read them with,
     * decided as {@see readableSortPaths()} decides them for an offset page. With `$admitting`,
     * each such level reads its value through the rows the reached types' providers admit, so a
     * row a scope hides sorts as `null`, as the in-memory walk needs.
     *
     * @param list<KeysetColumn> $columns
     *
     * @return array{list<KeysetColumn>, FilterPaths|null}
     *
     * @throws AuthorizationException when the client's sort reaches through a relation the caller may not read
     */
    public function keysetColumns(CollectionCriteria $criteria, array $columns, bool $admitting = false): array
    {
        $paths = $criteria->paths;
        if ($paths === null) {
            return [$columns, null];
        }

        $resolved = [];
        $directives = [];
        foreach ($columns as $column) {
            $sort = $column->sort;
            if ($sort instanceof SortByField && $sort->through !== null) {
                $sort = $paths->resolveSort($sort);
                \assert($sort instanceof SortByField);
                $directives[] = new SortDirective($sort, $column->descending);
            }
            $resolved[] = [$column, $sort];
        }
        if ($directives === []) {
            return [$columns, null];
        }

        $readable = $this->readableSortPaths($criteria, $paths, $directives);
        $admit = $admitting ? $readable->rowAdmission() : null;

        return [\array_map(static function (array $pair) use ($admit): KeysetColumn {
            [$column, $sort] = $pair;
            if (!$sort instanceof SortByField || $sort->through === null) {
                return $column;
            }

            return new KeysetColumn($column->column, $column->descending, $admit !== null ? new AdmittedKeysetSort($sort, $admit) : $sort);
        }, $resolved), $readable];
    }

    /**
     * `$directive` with a sort reaching through relations resolved against the type the
     * criteria run against.
     */
    private static function resolvedDirective(CollectionCriteria $criteria, SortDirective $directive): SortDirective
    {
        $sort = $criteria->paths?->resolveSort($directive->sort) ?? $directive->sort;

        return $sort === $directive->sort ? $directive : new SortDirective($sort, $directive->descending);
    }

    /**
     * The resource's `defaultSort()` directives, validated against the declared
     * sort vocabulary exactly as a requested sort is: each default must name a
     * declared sort (else {@see SortParamUnrecognized}, a server-config error).
     * Returns the directives unchanged when valid — they already carry their
     * direction — so the same handler executes them as a requested sort.
     *
     * @return list<SortDirective>
     *
     * @throws SortParamUnrecognized when a default sort names an undeclared sort
     */
    private function defaultDirectives(CollectionCriteria $criteria): array
    {
        if ($criteria->defaultSort === [] || $criteria->defaultSortResolved) {
            return $criteria->defaultSort;
        }

        foreach ($criteria->defaultSort as $directive) {
            $key = $directive->sort->key();
            if (SortMatcher::match($criteria->sorts, $key, $criteria->request) === null) {
                throw new SortParamUnrecognized($key);
            }
        }

        return $criteria->defaultSort;
    }
}
