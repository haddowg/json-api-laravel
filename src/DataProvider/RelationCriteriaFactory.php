<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorWindow;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\TargetsColumn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\OpenApi\PivotFields;

/**
 * Owns, once, the related-collection query-assembly the related endpoint
 * ({@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler}) and the `?withCount`
 * count batcher ({@see RelationCountBatcher}) both need: the per-relation paginator chain
 * and the related-resource ⊕ relation-scoped filter/sort vocabulary merge that rides a
 * {@see CollectionCriteria} (bundle ADR 0057, re-idiomized).
 *
 * A stateless collaborator — it holds no state and reads everything off its arguments, so
 * a single shared instance serves every relation of every request. It does not execute
 * the criteria (the provider's {@see DataProviderInterface::fetchRelatedCollection()} owns
 * that) and does not touch the PRIMARY-collection path, whose 2-tier paginator chain
 * (`resource -> server default`, no relation) is a different shape.
 *
 * **Pivot vocabulary.** A pivot filter is author-declared on the relation with a
 * `pivot.`-prefixed column (`Where::make('position', 'pivot.position')`); a pivot sort is
 * derived from each declared pivot field (`?sort=position`), columned `pivot.<column>`. Both
 * reach the criteria only when the caller passes `includePivotFields` (a provider that can
 * query the join, see {@see QueriesPivotColumnsInterface}); otherwise every `pivot.`-columned
 * filter is dropped, so the key is an unrecognised `400` rather than a query against a
 * column the provider cannot reach. The Eloquent handlers resolve the `pivot.` prefix to the
 * relation's join table.
 */
final class RelationCriteriaFactory
{
    /**
     * The column prefix that routes a relation filter or sort to the pivot join table.
     */
    public const string PIVOT_PREFIX = 'pivot.';

    /**
     * The per-relation paginator for a related to-many collection: the relation's own
     * paginator, else the related resource's, else the server default — the 3-tier chain
     * (`relation -> related resource -> server default`). The related resource is `null`
     * for a polymorphic to-many (no single related type), collapsing the chain to
     * `relation -> server default`.
     *
     * The fallback is composed bottom-up: the related resource resolves against the server
     * default (`$relatedResource->pagination($serverDefault)`), and that resolved value
     * feeds `$relation->pagination($fallback)` — where the relation's explicit
     * `withoutPagination()` returns `null` regardless of the fallback (the opt-out
     * short-circuits before the `?? $fallback`).
     */
    public function paginatorFor(RelationInterface $relation, ?AbstractResource $relatedResource, Server $server): ?PaginatorInterface
    {
        $serverDefault = $server->defaultPaginator();
        $fallback = $relatedResource?->pagination($serverDefault) ?? $serverDefault;

        return $relation->pagination($fallback);
    }

    /**
     * Assembles the {@see CollectionCriteria} for a related to-many collection, resolving
     * the requested `filter[…]`/`sort` against the related resource's vocabulary *merged*
     * with the relation's own scoped {@see RelationInterface::filters()}/
     * {@see RelationInterface::sorts()} — extra filters/sorts a relation declares for this
     * ONE relationship endpoint (core ADR 0051), never reachable on the primary
     * `/{relatedType}` collection.
     *
     * On a key clash the relation wins over the related resource, preserving the merge
     * order `[...resourceFilters, ...relationFilters]` then keyed by `->key()`.
     * `defaultSort` is the related resource's default order (empty for a polymorphic
     * to-many); the merged vocabulary rides the criteria so both providers' existing
     * handlers apply it unchanged (core ADR 0044).
     *
     * `$wantsCount` rides onto the criteria: only the related endpoint passes `true` (the
     * relation's paginator opted in via `withCount()`, or the client asked
     * `?withCount=_self_` under a countable() relation); the count batcher leaves the
     * default `false`, so its path stays count-free (the relationship-object totals come
     * from the separate {@see \haddowg\JsonApi\Serializer\RelationshipCountInterface} seam).
     *
     * `$includePivotFields` keeps the relation's `pivot.`-columned filters and adds the pivot
     * field sorts. A cursor window gets the pivot filters but not the pivot sorts: the keyset
     * reads its columns off the related model, never the join.
     */
    public function criteriaFor(
        QueryParameters $queryParameters,
        ?AbstractResource $relatedResource,
        RelationInterface $relation,
        ?WindowInterface $window,
        bool $wantsCount = false,
        bool $includePivotFields = false,
    ): CollectionCriteria {
        $relationFilters = $includePivotFields
            ? $relation->allFilters()
            : \array_values(\array_filter($relation->allFilters(), static fn(FilterInterface $filter): bool => !self::targetsPivot($filter)));

        $relationSorts = $relation->sorts();
        if ($includePivotFields && !$window instanceof CursorWindow) {
            $relationSorts = $this->mergeSorts($relationSorts, self::pivotSorts($relation));
        }

        return new CollectionCriteria(
            $queryParameters,
            $this->mergeFilters($relatedResource?->allFilters() ?? [], $relationFilters),
            $this->mergeSorts($relatedResource?->allSorts() ?? [], $relationSorts),
            $window,
            $relatedResource?->defaultSort() ?? [],
            wantsCount: $wantsCount,
        );
    }

    /**
     * One sort per declared pivot field, keyed by the field name and columned
     * `pivot.<column>` so the provider orders by the join table's column.
     *
     * @return list<SortInterface>
     */
    private static function pivotSorts(RelationInterface $relation): array
    {
        $sorts = [];
        foreach (PivotFields::sortsFor($relation) as $sort) {
            $sorts[] = SortByField::make($sort->key(), self::PIVOT_PREFIX . $sort->column);
        }

        return $sorts;
    }

    /**
     * Whether a filter compares a `pivot.`-prefixed column. A filter with no single column
     * (an existence or dotted-path filter, a custom filter) never targets the pivot.
     */
    private static function targetsPivot(FilterInterface $filter): bool
    {
        $column = match (true) {
            $filter instanceof TargetsColumn => $filter->targetColumn(),
            $filter instanceof WhereNull, $filter instanceof WhereNotNull => $filter->column,
            default => '',
        };

        return \str_starts_with($column, self::PIVOT_PREFIX);
    }

    /**
     * Merges two filter vocabularies, keyed by {@see FilterInterface::key()} so a clash
     * resolves to the later list's declaration (the more specific scope wins, core ADR
     * 0051). The order `[...$resourceFilters, ...$relationFilters]` is preserved before
     * the dedup; returned as a list for the {@see CollectionCriteria}.
     *
     * @param list<FilterInterface> $resourceFilters
     * @param list<FilterInterface> $relationFilters
     *
     * @return list<FilterInterface>
     */
    private function mergeFilters(array $resourceFilters, array $relationFilters): array
    {
        $merged = [];
        foreach ([...$resourceFilters, ...$relationFilters] as $filter) {
            $merged[$filter->key()] = $filter;
        }

        return \array_values($merged);
    }

    /**
     * Merges two sort vocabularies, keyed by {@see SortInterface::key()} so a clash
     * resolves to the later list's declaration (core ADR 0051). The order
     * `[...$resourceSorts, ...$relationSorts]` is preserved before the dedup; returned as
     * a list for the {@see CollectionCriteria}.
     *
     * @param list<SortInterface> $resourceSorts
     * @param list<SortInterface> $relationSorts
     *
     * @return list<SortInterface>
     */
    private function mergeSorts(array $resourceSorts, array $relationSorts): array
    {
        $merged = [];
        foreach ([...$resourceSorts, ...$relationSorts] as $sort) {
            $merged[$sort->key()] = $sort;
        }

        return \array_values($merged);
    }
}
