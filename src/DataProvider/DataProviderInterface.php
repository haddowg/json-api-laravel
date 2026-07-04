<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The read-half data-source SPI: the storage-agnostic contract the operation
 * handler delegates to for `GET /{type}` and `GET /{type}/{id}` (and, in later
 * phases, the related/relationship read endpoints and `?include`/`?withCount`
 * batching).
 *
 * A provider is resolved per resource type via {@see DataProviderRegistry::forType()}:
 * {@see supports()} tells the registry which type(s) a provider answers for. Writes
 * (create/update/delete) land in the separate {@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface}
 * persister SPI; this interface stays read-only.
 *
 * {@see fetchCollection()} receives a fully-resolved {@see CollectionCriteria}
 * (declared filter/sort vocabularies, requested query parameters, pagination
 * window) — the handler does the resolving, the provider only matches and executes,
 * so every provider agrees on the spec semantics and differs only in *execution*.
 * That is what keeps the {@see InMemoryDataProvider} an attributable conformance
 * witness for the Eloquent reference provider.
 *
 * `TEntity` is the domain-object type the provider yields — covariant, so a
 * single-model provider (`DataProviderInterface<Article>`) is substitutable wherever
 * a `DataProviderInterface<object>` is expected (the registry holds the heterogeneous
 * set that way). A multi-type provider like the Eloquent one implements
 * `DataProviderInterface<object>`.
 *
 * Most implementations extend {@see AbstractDataProvider}, which supplies neutral
 * default bodies for the six relationship/batch/pivot seams so a thin provider need
 * only write {@see supports()}, {@see fetchOne()} and {@see fetchCollection()}.
 *
 * @template-covariant TEntity of object
 */
interface DataProviderInterface
{
    /**
     * Whether this provider answers for the given resource type.
     */
    public function supports(string $type): bool;

    /**
     * The single resource of `$type` with `$id`, or `null` when none exists
     * (the handler maps `null` to a JSON:API `404`).
     *
     * @return TEntity|null
     */
    public function fetchOne(string $type, string $id): ?object;

    /**
     * The collection of resources of `$type` satisfying `$criteria`: filtered
     * and sorted per the requested parameters, windowed when the criteria carry
     * a pagination window (in which case the result also carries the pre-window
     * total when a count was requested).
     *
     * @return CollectionResult<TEntity>
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult;

    /**
     * The related collection of `$relatedType` reachable from `$parent` through
     * `$relation` (a to-many), scoped to the parent then filtered, sorted and
     * windowed per `$criteria` — the related-endpoint twin of {@see fetchCollection()}.
     * The criteria carry the **related** type's declared filter/sort vocabularies and
     * the per-relation pagination window.
     *
     * The endpoint total is gated by the relation's {@see RelationInterface::isCountable()}:
     * a **countable** relation's windowed fetch computes the pre-window total and returns
     * it on the result ({@see CollectionResult::$total}), so the handler emits
     * `meta.page.total` + a `last` link; a **non-countable** relation's windowed fetch is
     * **count-free** — it runs no `COUNT`, returns a `null` total with
     * {@see CollectionResult::$windowed} `true` and {@see CollectionResult::$hasMore} set
     * (from probing one item past the window). An unwindowed fetch returns a plain
     * collection.
     *
     * @return CollectionResult<TEntity>
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult;

    /**
     * The related value(s) of `$relation` (a monomorphic to-many OR to-one) for a
     * whole PAGE of parents, each scoped/filtered/sorted/windowed per `$criteria`, as a
     * {@see RelatedBatch} keyed by parent wire id — the batched, page-at-a-time twin of
     * {@see fetchRelatedCollection()} that loads a whole `?include` tree without N+1.
     *
     * A **to-many** relation's per-parent result is its windowed page (or, in
     * plain-include fast-path mode — empty criteria + null window — its WHOLE related
     * set). A **to-one** relation's per-parent result is a 0-or-1 {@see CollectionResult}
     * carrying its single target. A relation the provider cannot batch (a
     * computed/`extractUsing` column that is not a real association, or a composite-id
     * target) returns an empty {@see RelatedBatch}, so the caller's write-back is a no-op
     * and the relation renders lazily.
     *
     * Keyed by each parent's JSON:API (wire) id, exactly as {@see countRelated()} keys
     * its map, so a caller reconciles a result back to its parent object through the same
     * wire-id resolution. A parent with no related members is simply absent from the map;
     * {@see RelatedBatch::for()} fills it with an empty result.
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported      when sorting is requested but no sorts are declared
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized   when a requested sort field is not declared
     */
    public function fetchRelatedCollectionBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): RelatedBatch;

    /**
     * The cardinality of `$relation` (a countable to-many) for each parent in
     * `$parents`, as a `wire-id => count` map — the count-only batch seam that drives
     * `?withCount`. One grouped, pushed-down `COUNT` answers the whole page of parents,
     * so a collection render does not N+1; a single parent is a one-element batch.
     *
     * The count is over the relation's **filtered** set: `$criteria` carries the merged
     * related-collection filter vocabulary and the request's `relatedQuery[<rel>][filter]`
     * for this relation (no window, since a count needs no page; no sort, since order is
     * irrelevant to a count). In the common case the relation carries no relatedQuery
     * filter, so `$criteria` is empty and the count is raw membership. An unrecognised
     * filter key still `400`s.
     *
     * `$type` is the **parent** resource type (the relation lives on the parent). The map
     * is keyed by each parent's JSON:API (wire) id. A parent whose filtered set is empty
     * reports `0` (not absent).
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @return array<int|string, int> `parentWireId => count` (an integer-PK wire id is a
     *                                numeric string, which PHP stores as an int array key)
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a relatedQuery filter key is not declared
     */
    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array;

    /**
     * Whether the single related object of a monomorphic TO-ONE `$relation` survives
     * `$criteria`'s (merged) filters — the to-one twin of {@see fetchRelatedCollection()},
     * answering "does this one related object match?" for the single-resource to-one
     * surfaces (the related endpoint `GET /{type}/{id}/{toOneRel}?filter[…]`, the
     * relationship endpoint, and the `relatedQuery[<toOneRel>][filter]` profile path on a
     * single parent). When it returns `false` the handler nulls the to-one; when `true`
     * it renders the related object unchanged.
     *
     * `$criteria` carries the relation-scoped ({@see RelationInterface::filters()}) +
     * related-resource filter vocabulary the to-many related endpoint resolves, and never
     * a window/sort (a single member has neither). The probe is read-only.
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     */
    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool;

    /**
     * The BATCHED twin of {@see relatedToOneMatches()} for a whole PAGE of parents — the
     * include/primary path of the `relatedQuery[<toOneRel>][filter]` profile, run ONCE
     * over the page so the include does not N+1. For each parent it answers whether that
     * parent's single to-one target satisfies `$criteria`'s filters, as a `wire-id => bool`
     * map keyed exactly as {@see countRelated()} / {@see fetchRelatedCollectionBatch()}, so
     * the caller can reconcile each result back to its parent and null the property when
     * the target does not match.
     *
     * A parent whose to-one is `null` short-circuits to `false`. A parent absent from the
     * map is treated by the caller as a no-match (nulled). A polymorphic to-one is out of
     * scope (no shared filter vocabulary), so this is only ever called for a monomorphic
     * to-one.
     *
     * @param list<object> $parents the already-fetched page of parents (the handler holds it)
     *
     * @return array<string, bool> `parentWireId => target-matches`
     *
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not declared
     */
    public function relatedToOneMatchesBatch(
        string $parentType,
        array $parents,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): array;

    /**
     * The EXISTING pivot meta for the members currently in `$parent`'s pivot `$relation`
     * — `relatedId => [pivotField => wire value]` — read straight from storage with no
     * filter or window. The validator folds a stored pivot row under an incoming linkage
     * member's meta so an existing-member partial pivot update validates in the **update**
     * (preserved-value) context while a genuinely-new member still validates in the create
     * (new-row) context.
     *
     * A non-pivot relation, a pivot relation with no pivot fields, or a provider that
     * cannot store pivot data (the in-memory witness, a custom store) returns `[]` — every
     * incoming member is then treated as new (create context).
     *
     * @param object $parent the already-loaded parent (the handler holds it); avoids a re-fetch
     *
     * @return array<string, array<string, mixed>> `relatedId => [pivotField => wire value]`
     */
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array;
}
