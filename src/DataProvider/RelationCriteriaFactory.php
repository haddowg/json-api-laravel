<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterFamilyInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\HasDefaultValue;
use haddowg\JsonApi\Resource\Filter\RelationFilters;
use haddowg\JsonApi\Resource\Filter\TargetsColumn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\OpenApi\PivotFields;

/**
 * Owns, once, the related-collection query-assembly the related endpoint
 * ({@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler}) and the `?withCount`
 * count batcher ({@see RelationCountBatcher}) both need: the per-relation paginator chain
 * and the related-resource ⊕ relation-scoped filter/sort vocabulary merge that rides a
 * {@see CollectionCriteria} (re-idiomized).
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
 * filter is dropped, including one a filter family resolves, so the key is an unrecognised
 * `400` rather than a query against a column the provider cannot reach. The Eloquent
 * handlers resolve the `pivot.` prefix to the relation's join table, whichever key matched.
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
     * ONE relationship endpoint (core's docs/relations.md), never reachable on the primary
     * `/{relatedType}` collection.
     *
     * On a key clash the relation wins over the related resource, preserving the merge
     * order `[...resourceFilters, ...relationFilters]`; core's {@see FilterMatcher::merge()}
     * keeps a filter family and an exact filter of the same key apart.
     * `defaultSort` is the relation's own default order, else the related resource's (empty
     * for a polymorphic to-many); the merged vocabulary rides the criteria so both
     * providers' existing handlers apply it unchanged (core's docs/sorts.md).
     *
     * `$wantsCount` rides onto the criteria: only the related endpoint passes `true` (the
     * relation's paginator opted in via `withCount()`, or the client asked
     * `?withCount=_self_` under a countable() relation); the count batcher leaves the
     * default `false`, so its path stays count-free (the relationship-object totals come
     * from the separate {@see \haddowg\JsonApi\Serializer\RelationshipCountInterface} seam).
     *
     * `$includePivotFields` keeps the relation's `pivot.`-columned filters and adds the pivot
     * field sorts, a cursor window's keyset included, for a read the provider runs over the
     * join: the related and relationship endpoints, a Relationship Queries window and its
     * count. `$queriesPivotColumns` adds the pivot sorts alone.
     *
     * `$request` rides onto the criteria so a filter or sort family can resolve a requested key.
     * `$server` lets each matched filter's traversal path resolve against the related type's
     * fields ({@see FilterPaths}); without it, or for a polymorphic to-many, every path ends on
     * a field.
     */
    public function criteriaFor(
        QueryParameters $queryParameters,
        ?AbstractResource $relatedResource,
        RelationInterface $relation,
        ?WindowInterface $window,
        bool $wantsCount = false,
        bool $includePivotFields = false,
        ?JsonApiRequestInterface $request = null,
        ?bool $queriesPivotColumns = null,
        ?SerializerResolverInterface $server = null,
    ): CollectionCriteria {
        $relationFilters = $includePivotFields ? $relation->allFilters() : self::withoutPivotFilters($relation->allFilters());
        $sorts = $this->sortsFor($relatedResource, $relation, $includePivotFields || ($queriesPivotColumns ?? false));
        [$defaultSort, $resolved] = $this->defaultSortFor($relatedResource, $relation, $sorts, $request);

        return new CollectionCriteria(
            $queryParameters,
            FilterMatcher::merge(self::relatedFilters($relatedResource, $relation), $relationFilters),
            $sorts,
            $window,
            $defaultSort,
            wantsCount: $wantsCount,
            request: $request,
            defaultSortResolved: $resolved,
            paths: $server !== null ? FilterPaths::of($server, $relatedResource) : null,
        );
    }

    /**
     * The criteria that reads a to-many's whole member set in its read order for document
     * linkage: the include batch and a linkage read with no include. It carries the sort
     * vocabulary and default order of {@see criteriaFor()}, the filters whose defaults the
     * related endpoint folds in with no `filter` requested, and no window, so the linkage
     * lists the members the related endpoint serves.
     */
    public function linkageCriteriaFor(
        ?AbstractResource $relatedResource,
        RelationInterface $relation,
        bool $includePivotFields,
        JsonApiRequestInterface $request,
    ): CollectionCriteria {
        $sorts = $this->sortsFor($relatedResource, $relation, $includePivotFields);
        [$defaultSort, $resolved] = $this->defaultSortFor($relatedResource, $relation, $sorts, $request);

        return new CollectionCriteria(
            new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: $request->getPagination()),
            filters: self::linkageFilters($relatedResource, $relation),
            sorts: $sorts,
            defaultSort: $defaultSort,
            request: $request,
            defaultSortResolved: $resolved,
        );
    }

    /**
     * Whether a to-many's members read through its provider differ from the ones its model
     * relation holds: it has a read order, its own or the related type's, or its related
     * endpoint folds in a filter default the relation doesn't drop.
     */
    public static function readsThroughProvider(?AbstractResource $relatedResource, RelationInterface $relation): bool
    {
        if (!$relation->isToMany()) {
            return false;
        }

        if (self::declaresOwnOrder($relation) || ($relatedResource?->defaultSort() ?? []) !== []) {
            return true;
        }

        foreach (self::linkageFilters($relatedResource, $relation) as $filter) {
            if ($filter instanceof HasDefaultValue && $filter->hasDefault()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The filters a linkage read folds the defaults of: the related type's and the
     * relation's own, without the pivot ones.
     *
     * @return list<FilterInterface>
     */
    private static function linkageFilters(?AbstractResource $relatedResource, RelationInterface $relation): array
    {
        return FilterMatcher::merge(self::relatedFilters($relatedResource, $relation), self::withoutPivotFilters($relation->allFilters()));
    }

    /**
     * The related type's filters as the relation applies them, without the defaults it
     * drops through `withoutFilterDefaults()`.
     *
     * @return list<FilterInterface>
     */
    private static function relatedFilters(?AbstractResource $relatedResource, RelationInterface $relation): array
    {
        return RelationFilters::relatedFilters($relation, $relatedResource?->allFilters() ?? []);
    }

    /**
     * Whether `$relation` has its own read order, its declared default or its position order,
     * which replaces the order an Eloquent relation method's `orderBy` gives its query, as a
     * requested sort does (ADR 0037).
     */
    public static function declaresOwnOrder(RelationInterface $relation): bool
    {
        return RelationSorts::ownDefault($relation) !== [];
    }

    /**
     * The relation's own read order: its declared default, else ascending by its position, a
     * related type's position field ordering by that field's column.
     *
     * @return list<SortDirective>
     */
    private static function ownOrder(RelationInterface $relation, ?AbstractResource $relatedResource): array
    {
        $own = RelationSorts::ownDefault($relation);
        if ($own === [] || $relation instanceof PivotRelationInterface
            || ($relation instanceof DefaultSortedRelationInterface && $relation->defaultSort() !== [])) {
            return $own;
        }

        $name = $own[0]->sort->key();
        foreach ($relatedResource?->allFields() ?? [] as $field) {
            if ($field->name() === $name && !$field instanceof RelationInterface) {
                return [new SortDirective(SortByField::make($name, $field->column() ?? $name), descending: false)];
            }
        }

        return $own;
    }

    /**
     * @return list<SortInterface>
     */
    private function sortsFor(?AbstractResource $relatedResource, RelationInterface $relation, bool $includePivotSorts): array
    {
        $relationSorts = $relation->sorts();
        if ($includePivotSorts) {
            $relationSorts = SortMatcher::merge($relationSorts, self::pivotSorts($relation));
        }

        return SortMatcher::merge($relatedResource?->allSorts() ?? [], $relationSorts);
    }

    /**
     * The order to read the relation's members in when the request names no sort, and
     * whether it is already resolved against the relation's sorts: the relation's own
     * `defaultSort()` or position order, resolved, else the related type's, which the applier matches as it
     * does a resource's. Each of the relation's own directives is matched to the sort `$sorts`
     * holds for the key, so a pivot field or `withSorts()` key orders by the column the
     * relation declares for it. A pivot key the provider can't query is refused at warm-up,
     * so one that reaches here unresolved is dropped. {@see \haddowg\JsonApi\Serializer\RelationOrderValidator}
     * has checked every other key at warm-up.
     *
     * @param list<SortInterface> $sorts
     *
     * @return array{list<SortDirective>, bool}
     */
    private function defaultSortFor(?AbstractResource $relatedResource, RelationInterface $relation, array $sorts, ?JsonApiRequestInterface $request): array
    {
        if (!self::declaresOwnOrder($relation)) {
            return [$relatedResource?->defaultSort() ?? [], false];
        }

        $pivotKeys = \array_map(static fn(SortInterface $sort): string => $sort->key(), PivotFields::sortsFor($relation));

        $directives = [];
        foreach (self::ownOrder($relation, $relatedResource) as $directive) {
            $key = $directive->sort->key();
            $matched = SortMatcher::match($sorts, $key, $request);
            if ($matched !== null) {
                $directives[] = new SortDirective($matched, $directive->descending);
            } elseif (!\in_array($key, $pivotKeys, true)) {
                $directives[] = $directive;
            }
        }

        return [$directives, true];
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
     * The filters minus those comparing a `pivot.` column. A family can't be judged until it
     * resolves a key, so it is narrowed to answer only the keys resolving to a non-pivot filter.
     *
     * @param list<FilterInterface> $filters
     *
     * @return list<FilterInterface>
     */
    private static function withoutPivotFilters(array $filters): array
    {
        $kept = [];
        foreach ($filters as $filter) {
            if ($filter instanceof FilterFamilyInterface) {
                $kept[] = new NarrowedFilterFamily($filter, static fn(FilterInterface $resolved): bool => !self::targetsPivot($resolved));
            } elseif (!self::targetsPivot($filter)) {
                $kept[] = $filter;
            }
        }

        return $kept;
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
}
