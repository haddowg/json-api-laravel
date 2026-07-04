<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Server\Server;

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
 * **Phase scope (3a).** This is the reduced port: it assembles the filter/sort/pagination
 * criteria for a monomorphic related collection. The bundle's pivot-filter branches
 * (`withPivotCasts`/`pivotAliases`/the `pivot.` prefix) and the alias-aware push-down they
 * feed are a pivot-WRITE/query concern (belongsToMany filter/sort) deferred with the
 * Eloquent pivot machinery; pivot READ (`meta.pivot`) needs no pivot filters, so 3a always
 * merges the plain (non-pivot) vocabulary.
 */
final class RelationCriteriaFactory
{
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
     */
    public function criteriaFor(
        QueryParameters $queryParameters,
        ?AbstractResource $relatedResource,
        RelationInterface $relation,
        ?WindowInterface $window,
        bool $wantsCount = false,
    ): CollectionCriteria {
        return new CollectionCriteria(
            $queryParameters,
            $this->mergeFilters($relatedResource?->filters() ?? [], $relation->filters()),
            $this->mergeSorts($relatedResource?->allSorts() ?? [], $relation->sorts()),
            $window,
            $relatedResource?->defaultSort() ?? [],
            wantsCount: $wantsCount,
        );
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
