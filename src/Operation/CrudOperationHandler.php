<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RelationshipCountNotAllowed;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Request\RelatedQuery;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\SupportsSingular;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\RequestBaseUri;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCountBatcher;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\DataProvider\RelationshipWindowBatcher;
use haddowg\JsonApiLaravel\Serializer\PivotMetaSerializer;
use haddowg\JsonApiLaravel\Serializer\PivotParentSerializer;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipCount;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipLinkage;
use haddowg\JsonApiLaravel\Serializer\RequestScopedRelationshipPagination;
use haddowg\JsonApiLaravel\Serializer\WindowedRelationshipLinkage;
use haddowg\JsonApiLaravel\Serializer\WindowedRelationshipPagination;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;
use haddowg\JsonApiLaravel\Validation\FilterValueValidator;
use haddowg\JsonApiLaravel\Validation\ResourceValidator;

/**
 * The single generic operation handler, wired via `Server::withHandler()` so
 * `Server::dispatch()` has a target — the Laravel twin of the Symfony bundle's
 * `CrudOperationHandler`. It drives the whole-resource read + write surface over the
 * per-type {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface} /
 * {@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface} resolved from the
 * two registries.
 *
 * **Read** (`GET /{type}` and `GET /{type}/{id}`). A single fetch maps a missing resource
 * to a JSON:API `404` (the handler RETURNS an {@see ErrorResponse}, it does not throw). A
 * collection fetch resolves the resource's declared filter/sort vocabularies, default sort
 * and pagination strategy into a {@see CollectionCriteria}, asks the provider to execute
 * it, and renders the G21 §6a matrix: a **singular** filter collapses to a zero-to-one
 * resource; a **cursor** (keyset) page renders through the paginator's `fromBoundaries()`
 * path carrying the provider-minted tokens; a **counted** page renders `meta.page.total` +
 * `links.last` and echoes `meta.total`; a **count-free** page renders self/first/prev/
 * next with `next` driven by `hasMore` and no total; a **fetch-all** (no paginator)
 * renders `meta.total` unconditionally.
 *
 * **Write** (`POST /{type}`, `PATCH /{type}/{id}`, `DELETE /{type}/{id}`). Create asks the
 * persister for a blank instance, lets core's per-type hydrator populate it from the
 * request document (allow-list hydration — an undeclared attribute is dropped; a wrong
 * `type` is core's `409`, a forbidden client `data.id` core's `403`), commits it through
 * the persister, and renders the created resource as `201` + a `Location` header equal to
 * the resource's own `links.self` (core ADR 0054). Update loads the target (a miss is a
 * `404`), hydrates the incoming partial onto it, commits, and renders `200`. Delete loads
 * the target (a miss is a `404`), removes it, and renders `204`.
 *
 * **Relationship reads** (Phase 3a): {@see fetchRelated()} (`GET /{type}/{id}/{rel}`) and
 * {@see fetchRelationship()} (`GET …/relationships/{rel}`) render the related resource(s) /
 * linkage per cardinality (monomorphic + polymorphic to-one AND to-many), gated by the
 * parent's read policy; the {@see fetch()} arms grow the `?include` batch-eager-load
 * ({@see RelatedIncludeBatcher}) and `?withCount` count ({@see RelationCountBatcher}) render
 * hooks. Relationship MUTATIONS, pivot writes, existence filters and the Relationship
 * Queries profile's windowed linkage are Phase 3b (their seams are left in place).
 *
 * The document-semantic validation (the always-on illuminate/validation bridge) and the
 * policy authorization gate hook into the create/update/delete arms; lifecycle events +
 * hooks are a later phase — every unhandled operation falls to the default arm, which
 * returns a `404` {@see ResourceNotFound} exactly like the bundle's `CrudOperationHandler`
 * default arm (never a `500`).
 */
final class CrudOperationHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly ResourceValidator $validator,
        private readonly FilterValueValidator $filterValidator,
        private readonly Authorizer $authorizer,
        private readonly TypeMetadataResolver $types,
        private readonly RelationCriteriaFactory $relationCriteria,
        private readonly RelatedIncludeBatcher $includeBatcher,
        private readonly RelationCountBatcher $countBatcher,
        private readonly RelationshipWindowBatcher $windowBatcher,
        private readonly RequestScopedRelationshipCount $relationshipCount,
        private readonly RequestScopedRelationshipPagination $relationshipPagination,
        private readonly RequestScopedRelationshipLinkage $relationshipLinkage,
    ) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse
    {
        // Clear the request-scoped `?withCount` seam at the very start of EVERY dispatch, so a
        // prior request's batched counts can never bleed into an arm that does not itself
        // install them (fetchRelationship, the to-one fetchRelated branch, every write). Each
        // read arm re-installs it below with the page it just fetched. The handler is a
        // singleton, so this per-dispatch reset holds regardless of the count holder's
        // container lifetime (the Symfony bundle's twin resets via kernel.reset; this is the
        // Laravel-side equivalent, not the — for a memoized Server, ineffective — scoped()
        // binding its own docblock once floated). The Relationship Queries profile's pagination
        // + linkage holders are cleared here too, so a prior profile read's windowed
        // pages/linkage can never leak into a render that does not re-install them (each
        // windowing arm re-sets them below).
        $this->relationshipCount->set(null);
        $this->relationshipPagination->set(null);
        $this->relationshipLinkage->set(null);

        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            $operation instanceof FetchRelatedOperation => $this->fetchRelated($operation),
            $operation instanceof FetchRelationshipOperation => $this->fetchRelationship($operation),
            $operation instanceof CreateResourceOperation => $this->create($operation),
            $operation instanceof UpdateResourceOperation => $this->update($operation),
            $operation instanceof DeleteResourceOperation => $this->delete($operation),
            $operation instanceof UpdateRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
            $operation instanceof AddToRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
            $operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    private function fetch(FetchResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $type = $operation->target()->type;
        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        $request = $operation->context()->httpRequest();
        $request = $request instanceof JsonApiRequestInterface ? $request : null;

        $id = $operation->target()->id;
        if ($id !== null) {
            $model = $provider->fetchOne($type, $id);
            if ($model === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            // The `view` policy gate authorizes the loaded model (404 already resolved),
            // before it is rendered — a denial is a `403` (thrown, rendered by the
            // exception renderer).
            $this->authorizer->authorize($type, Operation::FetchOne, $model);

            // Batch eager-load the effective ?include tree (explicit or the resource's
            // default-include fallback) so includes do not N+1; a single resource is
            // preloaded as a one-element list. Window each rendered to-many relation under the
            // Relationship Queries profile (page 1 of its relatedQuery-ordered/filtered set,
            // supplied out-of-band) BEFORE the count hook (windows → counts), then install the
            // ?withCount batched counts for this resource so its relationship objects render
            // meta.total.
            $this->preloadIncludes($server, [$model], $type, $request);
            $this->applyRelationshipWindows($server, $type, [$model], $request);
            $this->applyRelationshipCounts($server, $type, [$model], $request);

            return DataResponse::fromResource($model, $serializer);
        }

        // A bare serializer/hydrator pair declares no field inventory, so it has no
        // filter/sort vocabulary and no resource-level paginator.
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        $filters = $resource?->filters() ?? [];

        // The always-on filter-value gate (PLAN decision 6): a client-supplied
        // `filter[<key>]` value is validated against the filter's declared value
        // constraints BEFORE the provider runs, so a mistyped value (e.g.
        // `filter[trackRange][min]=banana`, or a calendar-invalid DateRange bound) is a
        // clean `400` FILTER_VALUE_INVALID rather than a silent non-match or a driver
        // error. The raw requested map is validated (never the author-set defaults).
        $this->filterValidator->validate($operation->queryParameters()->filter, $filters);

        // The `viewAny` policy gate authorizes the collection BEFORE the query runs, on
        // a blank instance minted for the type's class (so a policy's `viewAny($user)` is
        // called). A read-only type has no persister to mint one — but a DECLARED `policy:`
        // is still enforced against the resource-class token (never fail-open); only the
        // no-policy Gate path stays inert on a null subject (the model's policy cannot be
        // resolved without an instance).
        $listSubject = $this->persisters->supportsType($type) ? $this->persisters->forType($type)->instantiate($type) : null;
        $this->authorizer->authorize($type, Operation::FetchCollection, $listSubject);

        // A singular filter the client applied collapses the collection to a
        // zero-to-one response — a single resource (the first match) or null, never
        // an array, and never paginated (core ADR 0039).
        $singular = $this->appliesSingularFilter($filters, $operation->queryParameters());

        // The resource's pagination() return is the single source of truth (G21):
        // used verbatim, with `null` meaning *no pagination* (fetch-all). The base
        // impl returns the resolved server default. A singular filter collapses to a
        // zero-to-one response, so it is never paginated.
        $paginator = $singular ? null : ($resource !== null ? $resource->pagination($server->defaultPaginator()) : $server->defaultPaginator());
        $window = $paginator !== null && $request !== null ? $paginator->window($request) : null;

        // The single COUNT decision (G21): a count-based paginator counts when its
        // own withCount() author opt-in flipped it, OR the client asked
        // `?withCount=_self_` (already 400-ed by core's document gate if the resource
        // is not countable(), so an accepted `_self_` here implies countable). The
        // cursor strategy is inherently count-free, so it is excluded.
        $wantsCount = $paginator !== null
            && !($paginator instanceof CursorPaginator)
            && ($paginator->wantsCount() || ($request !== null && $request->countsRelationship('_self_')));

        $result = $provider->fetchCollection($type, new CollectionCriteria(
            $operation->queryParameters(),
            $filters,
            $resource?->allSorts() ?? [],
            $window,
            // Applied only when the request carries no `sort` (core ADR 0044).
            $resource?->defaultSort() ?? [],
            wantsCount: $wantsCount,
        ));

        $items = $this->materialize($result);

        // Batch eager-load the effective ?include tree across the whole page (so includes
        // do not N+1), window each rendered to-many relation under the Relationship Queries
        // profile in ONE batched push-down per relation (windows → counts), and install the
        // ?withCount batched counts in ONE grouped count per relation, before rendering —
        // covering the singular first match too.
        $this->preloadIncludes($server, $items, $type, $request);
        $this->applyRelationshipWindows($server, $type, $items, $request);
        $this->applyRelationshipCounts($server, $type, $items, $request);

        if ($singular) {
            return DataResponse::fromResource($items[0] ?? null, $serializer);
        }

        // A cursor (keyset) page: the provider minted the boundary tokens, so render
        // through the paginator's cursor path (CursorPaginator::fromBoundaries)
        // carrying the pre-minted prev/next tokens + the has-flags. `from`/`to` are
        // the wire ids of the first/last rendered rows (meta.page.from/to). This is
        // the only total-null primary path (a primary collection is otherwise always
        // countable), so the offset and fromCollection branches below stay
        // byte-identical (bundle ADR 0063).
        if ($result instanceof CursorCollectionResult && $paginator instanceof CursorPaginator && $request !== null) {
            $from = $items === [] ? null : $serializer->getId($items[0]);
            $to = $items === [] ? null : $serializer->getId($items[\array_key_last($items)]);

            return DataResponse::fromPage(
                $paginator->fromBoundaries(
                    $request,
                    $items,
                    $result->cursorBefore ?? '',
                    $result->cursorAfter ?? '',
                    $result->hasMore,
                    $result->hasPrevious,
                    from: $from,
                    to: $to,
                ),
                $serializer,
            );
        }

        // Fan the single resolved total to BOTH meta slots from one count (G21 §6a):
        // fetch-all renders meta.total unconditionally; a counted page carries
        // meta.page.total/links.last and echoes meta.total; a count-free page renders
        // self/first/prev/next (no total/last, next via hasMore) and NO meta.total.
        if ($paginator === null) {
            return DataResponse::fromCollection($items, $serializer)->withMeta(['total' => \count($items)]);
        }

        if ($request !== null && $result->total !== null) {
            return DataResponse::fromPage($paginator->paginate($request, $items, $result->total), $serializer)
                ->withMeta(['total' => $result->total]);
        }

        if ($request !== null && $result->windowed) {
            return DataResponse::fromPage($paginator->paginateWithoutCount($request, $items, $result->hasMore), $serializer);
        }

        return DataResponse::fromCollection($items, $serializer);
    }

    /**
     * `GET /{type}/{id}/{relationship}` — the related-resource(s) document. Loads the
     * parent, resolves the named relation (a `404` if absent or its related endpoint is
     * suppressed), gates the read (the parent's `view` policy, or the relation's own
     * `securityRead` override), then renders through the related type's serializer per
     * cardinality:
     *  - a **to-many** resolves the related resource's filter/sort/pagination vocabulary
     *    merged with the relation's own scoped filters/sorts into a {@see CollectionCriteria},
     *    asks the related provider's {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::fetchRelatedCollection()}
     *    to execute it (scoped to the parent), preloads the related page's own `?include`s,
     *    and renders a paginated {@see RelatedResponse::fromPage()} (counted / count-free per
     *    the relation's `countable()`) or a fetch-all {@see RelatedResponse::fromCollection()};
     *  - a **polymorphic to-many** has no single related type — no shared filter/sort
     *    vocabulary, no related-resource paginator — and renders its mixed members through a
     *    {@see PolymorphicSerializer}; its includes are not batch-preloaded (it renders lazily);
     *  - a **to-one** reads the related object off the parent, resolves the serializer FROM
     *    that object (so a polymorphic to-one renders the object's own type), and renders a
     *    single resource (`data:null` for an empty to-one); a `?filter` may null a monomorphic
     *    to-one via {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::relatedToOneMatches()}.
     *
     * The Relationship Queries profile's windowed linkage + pivot `meta.pivot` are phase 3b.
     */
    private function fetchRelated(FetchRelatedOperation $operation): RelatedResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null || !$relation->exposesRelatedEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        $this->gateRead($type, $parent, $relation);

        $relatedTypes = $relation->relatedTypes();
        $polymorphic = \count($relatedTypes) > 1;
        $relatedType = $relatedTypes[0] ?? $type;

        $request = $this->jsonApiRequest($operation->context());

        if ($relation->isToMany()) {
            $relatedResource = $polymorphic ? null : $this->types->resourceFor($server, $relatedType);
            $paginator = $this->relationCriteria->paginatorFor($relation, $relatedResource, $server);
            $window = $paginator?->window($request);

            // The related endpoint renders via CollectionDocument, which does not run core's
            // primary-collection `_self_` gate — so `?withCount=_self_` on a non-countable
            // relation is enforced here as a 400.
            if ($request->countsRelationship('_self_') && !$relation->isCountable()) {
                return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
            }

            $relWantsCount = $paginator !== null
                && !($paginator instanceof CursorPaginator)
                && ($paginator->wantsCount() || $request->countsRelationship('_self_'));

            $relatedProvider = $this->providers->forType($relatedType);

            $criteria = $this->relationCriteria->criteriaFor(
                $operation->queryParameters(),
                $relatedResource,
                $relation,
                $window,
                wantsCount: $relWantsCount,
            );

            // Validate a client-supplied filter value against the merged vocabulary (related
            // resource ⊕ relation-scoped), so a mistyped value is the same 400 as elsewhere.
            $this->filterValidator->validate($operation->queryParameters()->filter, $criteria->filters);

            $result = $relatedProvider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);

            $serializer = $polymorphic
                ? $this->polymorphicSerializer($relation, $server)
                : $server->serializerFor($relatedType);

            // A belongsToMany with declared pivot fields renders each related member's stored
            // pivot values as `meta.pivot` (ADR 0008): wrap the related serializer with the
            // per-member pivot map read off the parent (Eloquent renders it; the in-memory
            // witness returns none, so the wrap is a no-op there — pivot READ is Eloquent-only).
            if (!$polymorphic) {
                $pivotMap = $this->pivotMap($type, $parent, $relation);
                if ($pivotMap !== []) {
                    $serializer = new PivotMetaSerializer($serializer, $pivotMap);
                }
            }

            $items = $this->materialize($result);

            // A polymorphic page spans types (no single related type), so its includes are
            // not batch-preloaded, its relations are not windowed, and it carries no single
            // related type to count.
            if (!$polymorphic) {
                $this->preloadIncludes($server, $items, $relatedType, $request);
                $this->applyRelationshipWindows($server, $relatedType, $items, $request);
                $this->applyRelationshipCounts($server, $relatedType, $items, $request);
            }

            // Counted page: the single total fans to BOTH meta.page.total (inside the
            // count-based page) AND the universal top-level meta.total (G21 §6b).
            if ($paginator !== null && $result->total !== null) {
                return RelatedResponse::fromPage($paginator->paginate($request, $items, $result->total), $serializer, $relation->isCountable())
                    ->withMeta(['total' => $result->total]);
            }

            // A count-free page: a non-countable relation's windowed fetch carries no total,
            // only `hasMore` — render self/first/prev/next, no total/last.
            if ($paginator !== null && $result->windowed) {
                return RelatedResponse::fromPage($paginator->paginateWithoutCount($request, $items, $result->hasMore), $serializer, $relation->isCountable());
            }

            // Fetch-all (no paginator): the whole related set is materialized, so its size is
            // free — render meta.total unconditionally (G21 §5).
            return RelatedResponse::fromCollection($items, $serializer, $relation->isCountable())
                ->withMeta(['total' => \count($items)]);
        }

        // A to-one related endpoint has no collection, so `?withCount=_self_` is invalid here.
        if ($request->countsRelationship('_self_')) {
            throw new RelationshipCountNotAllowed(['_self_']);
        }

        $related = $relation->readValue($parent, $request);

        // A polymorphic to-one (MorphTo) has no single related resource and so no shared
        // filter vocabulary — any requested filter key is unrecognised (a 400), gated on the
        // requested filter being present so a filter on an empty polymorphic to-one still 400s.
        if ($polymorphic) {
            $filter = $this->toOneRequestedFilter($operation->queryParameters(), $relation, $request);
            if ($filter !== []) {
                throw new FilterParamUnrecognized((string) \array_key_first($filter));
            }
        }

        // A relation filter that excludes the single related object nulls the to-one, so it
        // renders `data: null` — the to-one twin of the to-many endpoint's filtered
        // collection. Monomorphic only. The local `$related` is nulled (no parent mutation).
        if (\is_object($related) && !$polymorphic) {
            $filter = $this->toOneRequestedFilter($operation->queryParameters(), $relation, $request);
            if ($filter !== []) {
                $relatedResource = $this->types->resourceFor($server, $relatedType);
                $criteria = $this->relationCriteria->criteriaFor(
                    new QueryParameters(fields: [], includes: [], sort: [], filter: $filter, pagination: $request->getPagination()),
                    $relatedResource,
                    $relation,
                    null,
                );
                $this->filterValidator->validate($filter, $criteria->filters);

                if (!$this->providers->forType($relatedType)->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request)) {
                    $related = null;
                }
            }
        }

        $serializer = $relation->resolveSerializer($related, $server) ?? $server->serializerFor($relatedType);

        // Preload the related resource's own ?include tree (a single related object as a
        // one-element list) and install its `?withCount` counts, so a `?withCount=<rel>` named
        // against the rendered to-one target emits `meta.total` on its relationship object —
        // parity with the single-resource fetch, and (with the per-dispatch clear above) never
        // a stale count. The related type may have no provider of its own (only ever resolved
        // through the parent), so guard the resolution.
        if (\is_object($related) && !$polymorphic && $this->providers->supportsType($relatedType)) {
            $this->preloadIncludes($server, [$related], $relatedType, $request);
            $this->applyRelationshipCounts($server, $relatedType, [$related], $request);
        }

        return RelatedResponse::fromResource($related, $serializer);
    }

    /**
     * `GET /{type}/{id}/relationships/{relationship}` — the relationship-linkage document
     * (resource identifiers only). Loads the parent, resolves the named relation (a `404` if
     * absent or its relationship endpoint is suppressed), gates the read, and routes the
     * parent through the *parent* type's serializer with the relationship name set so the
     * transformer emits linkage.
     *
     * A monomorphic to-many relationship endpoint is a queryable, paginated collection at
     * parity with its related twin (`GET /{type}/{id}/{rel}`): when the client supplies
     * `?page`/`?sort`/`?filter` (or `?withCount=_self_`) it is windowed to page 1, whose
     * linkage + relationship-object pagination ride the request-scoped seams OUT-OF-BAND (never
     * a destructive write onto the parent property, which would corrupt a column-sharing sibling
     * relation). With no query parameters it renders the whole association off the loaded parent
     * exactly as before (the plain relationship GET — a deliberate divergence from the bundle's
     * always-paginate, preserving the Phase-3a full-linkage contract). A windowed *pivot*
     * relationship endpoint (the pivot page + its `meta.pivot`) rides the pivot machinery and
     * stays on its full-set path here.
     */
    private function fetchRelationship(FetchRelationshipOperation $operation): IdentifierResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null || !$relation->exposesRelationshipEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        $this->gateRead($type, $parent, $relation);

        // When the client addressed the relationship (linkage) endpoint with query parameters
        // (`?page`/`?sort`/`?filter`/`?withCount=_self_`), honour or reject them — NEVER silently
        // ignore them (the worst outcome: a client believing its sort/filter/page applied).
        $request = $this->jsonApiRequest($operation->context());
        if ($this->relationshipEndpointQueried($operation->queryParameters(), $request)) {
            $monomorphic = \count($relation->relatedTypes()) === 1;
            $isPivot = $relation instanceof BelongsToMany && $relation->pivotFields() !== [];

            if ($relation->isToMany() && $monomorphic && !$isPivot) {
                // A monomorphic, non-pivot to-many: window it, supplying the page-1 linkage +
                // pagination out-of-band. A miss (unknown filter/sort key, a `_self_` count on a
                // non-countable relation) surfaces the related endpoint's same error.
                $error = $this->windowRelationshipEndpoint($server, $type, $parent, $relation, $operation->queryParameters(), $request);
                if ($error !== null) {
                    return $error;
                }
            } else {
                // The endpoint cannot honour the client's query on THIS relation shape, so it is a
                // 400 rather than a silent full-set render: a to-one has no collection to
                // sort/page/count; a polymorphic to-many has no single related provider or shared
                // sort/filter vocabulary; a pivot to-many's windowed linkage (bundle ADR 0096 — the
                // pivot page + its `meta.pivot`) is a deliberately-deferred tail. See docs/adr/0010.
                return $this->rejectRelationshipEndpointQuery($operation->queryParameters(), $request);
            }
        }

        return IdentifierResponse::forRelationship(
            $parent,
            $this->relationshipLinkageSerializer($server, $type, $parent, $relation, $relationshipName),
            $relationshipName,
        );
    }

    /**
     * Whether the client addressed a relationship (linkage) endpoint with query parameters that
     * make it a windowed collection: an explicit `?page`/`?sort`/`?filter`, or a
     * `?withCount=_self_` naming this relationship's collection total. With none of these the
     * endpoint renders the whole association off the loaded parent (the plain relationship GET).
     */
    private function relationshipEndpointQueried(QueryParameters $queryParameters, JsonApiRequestInterface $request): bool
    {
        return $queryParameters->pagination !== []
            || $queryParameters->sort !== []
            || $queryParameters->filter !== []
            || $request->countsRelationship('_self_');
    }

    /**
     * Windows a monomorphic to-many relationship (linkage) endpoint to page 1 of the request's
     * `?sort`/`?filter` over the merged related-collection vocabulary (mirroring
     * {@see fetchRelated()}'s to-many arm), then supplies that page-1 linkage + its pagination
     * out-of-band via the request-scoped seams. Returns an {@see ErrorResponse} for a
     * `?withCount=_self_` on a non-countable relation (the linkage twin of the related
     * endpoint's `_self_` gate); `null` on success.
     */
    private function windowRelationshipEndpoint(
        Server $server,
        string $type,
        object $parent,
        RelationInterface $relation,
        QueryParameters $queryParameters,
        JsonApiRequestInterface $request,
    ): ?ErrorResponse {
        $relatedType = $relation->relatedTypes()[0] ?? $type;
        $relatedResource = $this->types->resourceFor($server, $relatedType);
        $paginator = $this->relationCriteria->paginatorFor($relation, $relatedResource, $server);
        $window = $paginator?->window($request);

        // `_self_` names THIS relationship's collection — a 400 on a non-countable relation (the
        // to-many linkage twin of fetchRelated's gate; the relationship document does not run
        // core's primary-collection `_self_` gate).
        if ($request->countsRelationship('_self_') && !$relation->isCountable()) {
            return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
        }

        $wantsCount = $paginator !== null
            && !($paginator instanceof CursorPaginator)
            && ($paginator->wantsCount() || $request->countsRelationship('_self_'));

        $criteria = $this->relationCriteria->criteriaFor($queryParameters, $relatedResource, $relation, $window, wantsCount: $wantsCount);
        $this->filterValidator->validate($queryParameters->filter, $criteria->filters);

        $result = $this->providers->forType($relatedType)->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
        $items = $this->materialize($result);

        $this->supplyWindowedRelationship($parent, $relation->name(), $items, $paginator, $result, $wantsCount, $request, $queryParameters);

        return null;
    }

    /**
     * Rejects a query the relationship (linkage) endpoint cannot honour on the addressed
     * relation shape — a to-one, a polymorphic to-many, or a pivot to-many (docs/adr/0010) — with
     * a `400` rather than silently rendering the full association. `?withCount=_self_` is the
     * related endpoint's same {@see RelationshipCountNotAllowed}; a filter mirrors
     * {@see fetchRelated()}'s polymorphic-to-one {@see FilterParamUnrecognized}; a `?sort`/`?page`
     * is an unsupported query parameter on this endpoint.
     */
    private function rejectRelationshipEndpointQuery(QueryParameters $queryParameters, JsonApiRequestInterface $request): ErrorResponse
    {
        if ($request->countsRelationship('_self_')) {
            return ErrorResponse::fromException(new RelationshipCountNotAllowed(['_self_']));
        }

        if ($queryParameters->filter !== []) {
            return ErrorResponse::fromException(new FilterParamUnrecognized((string) \array_key_first($queryParameters->filter)));
        }

        return ErrorResponse::fromException(new QueryParamUnrecognized($queryParameters->sort !== [] ? 'sort' : 'page'));
    }

    /**
     * Supplies the page-1 linkage and its pagination for a windowed to-many relationship
     * (linkage) endpoint OUT-OF-BAND through the request-scoped linkage/pagination seams, keyed
     * by the one rendered parent + relation (single-entry maps) — never a destructive write onto
     * the parent's relation property (which would corrupt a column-sharing sibling relation). A
     * paginated relation supplies its page so core emits the relationship object's
     * `first`/`prev`/`next`(/`last`) links in the spec's plain form; a relation with no paginator
     * is filtered/sorted but not sliced (no pagination entry).
     *
     * @param list<object>             $items  the page-1 linkage members
     * @param CollectionResult<object> $result the provider's windowed fetch result
     */
    private function supplyWindowedRelationship(
        object $parent,
        string $relationshipName,
        array $items,
        ?PaginatorInterface $paginator,
        CollectionResult $result,
        bool $wantsCount,
        JsonApiRequestInterface $request,
        QueryParameters $queryParameters,
    ): void {
        $objectId = \spl_object_id($parent);
        $this->relationshipLinkage->set(new WindowedRelationshipLinkage(
            [$objectId => [$relationshipName => new RelationshipLinkage($items)]],
        ));

        if ($paginator === null || ($result->total === null && !$result->windowed)) {
            return;
        }

        $queryString = (new RelatedQuery(
            $request->getSorting() === [] ? null : \implode(',', $request->getSorting()),
            $queryParameters->filter,
        ))->toPlainQueryString();

        $page = $wantsCount && $result->total !== null
            ? $paginator->paginate($request, $items, $result->total)
            : $paginator->paginateWithoutCount($request, $items, $result->hasMore);

        $this->relationshipPagination->set(new WindowedRelationshipPagination(
            [$objectId => [$relationshipName => new RelationshipPagination($page, $queryString)]],
        ));
    }

    /**
     * Batch eager-loads the effective `?include` tree rooted at `$entities` so an included
     * relationship does not N+1. A no-op with no request or no entities.
     *
     * @param list<object> $entities
     */
    private function preloadIncludes(Server $server, array $entities, string $type, ?JsonApiRequestInterface $request): void
    {
        if ($request === null || $entities === []) {
            return;
        }

        $this->includeBatcher->preload($server, $entities, $type, $request);
    }

    /**
     * Installs the per-render `?withCount` count seam for a read of `$type` over its fetched
     * `$items`, in ONE grouped count per named countable relation (no N+1), so core emits
     * `meta.total` on each relationship object the request named. Called on every read so it
     * also CLEARS the holder (installs `null`) when the request named no `?withCount`.
     *
     * @param list<object> $items
     */
    private function applyRelationshipCounts(Server $server, string $type, array $items, ?JsonApiRequestInterface $request): void
    {
        $this->relationshipCount->set(
            $request === null ? null : $this->countBatcher->batch($server, $type, $items, $request),
        );
    }

    /**
     * Installs the per-render relationship-window seam for a read of `$type` over its fetched
     * `$items` under the Relationship Queries profile: the {@see RelationshipWindowBatcher}
     * windows each rendered to-many relation to page 1 of its `relatedQuery`-ordered/filtered
     * set — on the Eloquent reference through the `groupLimit`/ROW_NUMBER SQL push-down
     * (ADR 0006) — and supplies that page-1 LINKAGE + relationship-object PAGINATION out-of-band
     * (NOT written back onto each parent), both maps swapped into the request-scoped holders the
     * memoized Server renders through, so core emits the windowed linkage and the relationship
     * object's pagination links in plain form while a column-sharing bystander relation still
     * renders its own membership.
     *
     * Called on every read (and write) so it also CLEARS the holders (installs `null`) when the
     * request did not negotiate the profile — a prior profile request's pages never leak into
     * this render. A no-op with no request to read the profile/relatedQuery from.
     *
     * @param list<object> $items the fetched page whose rendered to-many relations to window
     */
    private function applyRelationshipWindows(Server $server, string $type, array $items, ?JsonApiRequestInterface $request): void
    {
        $result = $request === null ? null : $this->windowBatcher->batch($server, $type, $items, $request);

        $this->relationshipPagination->set($result?->pagination);
        $this->relationshipLinkage->set($result?->linkage);
    }

    /**
     * Loads the parent resource of a related / relationship read through the read provider,
     * or `null` when there is no id (the handler maps `null` to a `404`).
     */
    private function loadParent(string $type, ?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return $this->providers->forType($type)->fetchOne($type, $id);
    }

    /**
     * Resolves the declared, non-hidden relation named `$name` on `$type`, or `null` when
     * the type declares no such relationship — the handler maps a `null` to a `404`.
     */
    private function resolveRelation(Server $server, string $type, string $name): ?RelationInterface
    {
        return $this->types->relationNamed($server, $type, $name);
    }

    /**
     * A `(type) => ?AbstractResource` resolver over the server, threaded into the linkage
     * validator so it can look up a related type's declared id format (the linkage-id-format
     * pass). Returns `null` for a type the server declares no resource for (a bare
     * serializer/hydrator pair — its id is then unconstrained).
     *
     * @return \Closure(string): ?AbstractResource
     */
    private function resourceResolver(Server $server): \Closure
    {
        return static fn(string $relatedType): ?AbstractResource => $server->hasResourceFor($relatedType)
            ? $server->resourceFor($relatedType)
            : null;
    }

    /**
     * Gates a related / relationship read (PLAN decision 7, re-idiomized from the bundle's
     * event gate to a policy gate): the parent's `view` policy (the same gate the primary
     * single fetch applies) authorizes the loaded parent, UNLESS the relation declares its
     * own read security — a `false` opts the relation out of any read gate, a string
     * overrides the ability the parent is authorized against.
     */
    private function gateRead(string $type, object $parent, RelationInterface $relation): void
    {
        $read = $relation->securityRead();
        if ($read === false) {
            return;
        }

        if (\is_string($read)) {
            $this->authorizer->authorizeAbility($type, $read, $parent);

            return;
        }

        $this->authorizer->authorize($type, Operation::FetchOne, $parent);
    }

    /**
     * A {@see PolymorphicSerializer} that renders a polymorphic to-many's mixed-type
     * members: for each member object it resolves the serializer among the relation's
     * declared types, throwing when no declared type serializes a related object.
     */
    private function polymorphicSerializer(RelationInterface $relation, Server $server): PolymorphicSerializer
    {
        return new PolymorphicSerializer(
            fn(mixed $object): SerializerInterface => $relation->resolveSerializer($object, $server)
                ?? throw new \LogicException(\sprintf('No declared type of the "%s" relationship serializes a related object.', $relation->name())),
        );
    }

    /**
     * The parent-type serializer for a relationship-linkage render ({@see fetchRelationship()}
     * and the pivot mutation echo), decorated so a `belongsToMany` pivot relation's linkage
     * identifiers carry their stored pivot values as `meta.pivot` (ADR 0008). A non-pivot
     * relation (or a provider that stores no pivot — the in-memory witness) renders through the
     * bare parent serializer unchanged, so `meta.pivot` is Eloquent-only.
     */
    private function relationshipLinkageSerializer(Server $server, string $type, object $parent, RelationInterface $relation, string $relationshipName): SerializerInterface
    {
        $serializer = $server->serializerFor($type);

        $pivotMap = $this->pivotMap($type, $parent, $relation);
        if ($pivotMap === []) {
            return $serializer;
        }

        $relatedType = $relation->relatedTypes()[0] ?? $type;

        return new PivotParentSerializer(
            $serializer,
            $relationshipName,
            $relation,
            $server,
            new PivotMetaSerializer($server->serializerFor($relatedType), $pivotMap),
        );
    }

    /**
     * The stored per-member pivot map (`relatedId => [field => value]`) for a `belongsToMany`
     * relation declaring pivot fields, read through the parent's provider seam
     * ({@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface::fetchRelationshipPivot()}),
     * or `[]` when the relation is not a pivot relation / declares no pivot fields / the
     * provider stores none (the in-memory boundary — pivot READ is Eloquent-only).
     *
     * @return array<string, array<string, mixed>>
     */
    private function pivotMap(string $type, object $parent, RelationInterface $relation): array
    {
        if (!$relation instanceof BelongsToMany || $relation->pivotFields() === []) {
            return [];
        }

        return $this->providers->forType($type)->fetchRelationshipPivot($type, $parent, $relation);
    }

    /**
     * The requested `filter[…]` for a to-one related endpoint: the operation's own `?filter`
     * merged with any `relatedQuery[<rel>][filter]` addressed to this relation's path under
     * the negotiated Relationship Queries profile, the relatedQuery taking precedence on a
     * key clash. Empty when neither is present (the common case — the to-one renders
     * unconditionally).
     *
     * @return array<string, mixed>
     */
    private function toOneRequestedFilter(QueryParameters $queryParameters, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        return [...$queryParameters->filter, ...$request->getRelatedQuery($relation->name())->filter];
    }

    /**
     * `POST /{type}` — create a single resource. Order mirrors the bundle's `create()`
     * arm (minus the Phase-3 embedded-relationship and Phase-4 event/hook lines): the
     * document-validation and authorization seams sit before hydrate/persist so the
     * follow-on Phase 2 work slots in without reordering.
     */
    private function create(CreateResourceOperation $operation): DataResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $type = $operation->target()->type;
        $body = $operation->body();
        $request = $this->jsonApiRequest($operation->context());

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        // The blank instance is minted first so the document validator can resolve the
        // owning Eloquent table for a UniqueEntity `Rule::unique` (pre-hydration, PLAN
        // decision 6); it stays the target the hydrator populates below.
        $entity = $persister->instantiate($type);

        // Document-semantic validation (the always-on illuminate/validation bridge) runs
        // here, creating: true, before hydration — a `422` document-first, before the
        // `create` policy gate below, so validation precedes authorization (bundle
        // parity: 422 before 403).
        if ($resource !== null) {
            $this->validator->validate($resource, $request, true, subject: $entity);
        }

        // The `create` policy gate authorizes the type here, before hydration —
        // validate-before-authorize preserves the bundle's 422-before-403. Create carries
        // no instance to the policy method, so the blank instance's class-string is used
        // (a policy's `create($user)`). The pristine-subject / authorize-before-hydrate
        // timing (and its entity-seam-422-after-403 + uniqueness-oracle consequences) is a
        // recorded divergence from the bundle — see docs/adr/0004.
        $this->authorizer->authorize($type, Operation::Create, $entity);

        // Whole-resource writes embedding `data.relationships` (Phase 3b): core's hydrator
        // would assign a scalar linkage id to a typed association property (a TypeError /
        // NOT-NULL 500), so the relationships are stripped from the body before hydration
        // and set through the persister's relationship seam instead — a full replacement,
        // the flush deferred to the single create() commit (bundle ADR 0018). Each embedded
        // linkage is validated with the same pass the dedicated endpoint uses (a `409`
        // resource-type conflict / `422` malformed linkage or pivot meta), before persist.
        // A to-one (owner-side FK) applies inline before the create; a to-many (join /
        // inverse FK) is DEFERRED to after the parent is keyed — an Eloquent belongsToMany
        // join insert needs the parent PK, a reference-storage divergence from the bundle's
        // uniform pre-create apply (docs/adr/0009). The `cannot*` gate is NOT applied on a
        // create (there is nothing to replace; a create sets the initial state).
        $relationships = $this->extractRelationships($server, $type, $body, true);
        $resolveResource = $this->resourceResolver($server);
        foreach ($relationships as $relationship) {
            $this->validator->validateRelationshipLinkage(
                $relationship['relation'],
                $relationship['linkage'],
                Mode::Replace,
                [],
                $body,
                embeddedRelationName: $relationship['relation']->name(),
                resolveResource: $resolveResource,
            );
        }
        [$beforeCreate, $deferred] = $this->partitionForCreateOrder($relationships);
        $this->applyRelationships($persister, $type, $entity, $beforeCreate, $body, true, flush: false);

        // Core's hydrator applies the id policy (client vs server-generated; a wrong
        // `type` 409s, a forbidden client id 403s) then allow-list-hydrates the
        // attributes; the throws propagate to the exception renderer. The
        // relationships are stripped so core never scalar-hydrates an association.
        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $entity);
        \assert(\is_object($entity));

        // The post-hydration entity seam (uniqueness's custom cousin): a resource's
        // EntityConstraintInterface constraints validate the hydrated entity before
        // persist. A no-op for a resource that declares none (UniqueEntity is folded into
        // the pre-hydration Rule::unique above).
        if ($resource !== null) {
            $this->validator->validateEntity($resource, $entity, true);
        }

        // The create() and the deferred (join / inverse-FK) relationship applies commit as
        // ONE unit: create() opens its own transaction, which nests as a savepoint under this
        // outer boundary, so a failing deferred apply (a QueryException on a join insert, an FK
        // violation) rolls the parent row back too — no orphaned, partially-related resource.
        // This restores the atomicity the bundle's single flush guarantees, despite the
        // Eloquent PK-before-join reordering (docs/adr/0009); a non-transactional persister
        // runs the closure directly (it owns its own atomicity).
        $entity = $this->writeTransactionally($persister, function () use ($persister, $type, $entity, $deferred, $body): object {
            // The persister commits and returns the entity with any store-generated id
            // populated (Eloquent auto-increment / the in-memory store's minted id). The
            // deferred embeds then apply now the parent carries its key (an Eloquent join
            // insert / inverse-FK reparent both need the parent PK), their per-op transactions
            // nesting as savepoints under this boundary.
            $created = $persister->create($type, $entity);
            $this->applyRelationships($persister, $type, $created, $deferred, $body, true, flush: true);

            return $created;
        });

        // A write response is the same resource document, so it honours `?include`, the
        // Relationship Queries profile (windowed linkage/pagination), and `?withCount` exactly
        // as a read does — preload → window → count over the created entity, before rendering.
        $this->preloadIncludes($server, [$entity], $type, $request);
        $this->applyRelationshipWindows($server, $type, [$entity], $request);
        $this->applyRelationshipCounts($server, $type, [$entity], $request);

        // The Location uses the resource's URI segment (its uriType), so it matches the
        // route a client GETs; a bare pair falls back to the type. It is resolved from
        // the request the SAME way the body's links are, so the Location and the created
        // resource's own `links.self` stay equal (core ADR 0054).
        $uriType = $server->hasResourceFor($type) ? $server->resourceFor($type)->uriType() : $type;
        $baseUri = RequestBaseUri::resolve($server->baseUri(), $request->getUri());

        return DataResponse::fromResource($entity, $serializer)
            ->withStatus(201)
            ->withHeader('Location', $baseUri . '/' . $uriType . '/' . $serializer->getId($entity));
    }

    /**
     * `PATCH /{type}/{id}` — update a single resource. A missing target is a `404`
     * (returned, not thrown); the incoming partial is allow-list-hydrated onto the
     * loaded target and committed, rendering `200` with the updated document (an
     * unsupplied attribute is left untouched).
     */
    private function update(UpdateResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $body = $operation->body();
        $request = $this->jsonApiRequest($operation->context());

        $provider = $this->providers->forType($type);
        $entity = $id !== null ? $provider->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $persister = $this->persisters->forType($type);
        $serializer = $server->serializerFor($type);
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        // Document-semantic validation runs here, creating: false, with the loaded target
        // so a PATCH validates the MERGED resource state (stored values overlaid by the
        // incoming partial), not the partial alone (bundle ADR 0049) — and the loaded
        // model supplies the UniqueEntity `Rule::unique` table + self-ignore id. A `422`
        // is raised before the `update` policy gate below (422 before 403).
        if ($resource !== null) {
            $this->validator->validate($resource, $request, false, existingObject: $entity, subject: $entity);
        }

        // The `update` policy gate authorizes the PRISTINE loaded target here, before
        // hydration mutates it — ownership is decided on stored state, not
        // attacker-influenced attributes (a deliberate, more-secure divergence from the
        // bundle's post-hydrate timing). See docs/adr/0004.
        $this->authorizer->authorize($type, Operation::Update, $entity);

        // Whole-resource writes embedding `data.relationships` (Phase 3b): stripped from the
        // body before hydration and set through the persister's relationship seam (bundle
        // ADR 0018), a full replacement per named association (never an incremental
        // add/remove). Each is validated with the endpoint's pass — folding the loaded
        // parent's stored pivot rows in for the merge-before-validate (an existing member's
        // omitted required pivot field keeps its stored value, no false 422). The apply is
        // deferred (`flush: false`) so the single update() commit below owns the flush.
        $relationships = $this->extractRelationships($server, $type, $body, false);
        $existingPivots = $this->existingPivots($server, $type, $body, $entity);
        $resolveResource = $this->resourceResolver($server);
        foreach ($relationships as $relationship) {
            $pivot = $existingPivots[$relationship['relation']->name()] ?? [];
            $this->validator->validateRelationshipLinkage(
                $relationship['relation'],
                $relationship['linkage'],
                Mode::Replace,
                $pivot,
                $body,
                embeddedRelationName: $relationship['relation']->name(),
                resolveResource: $resolveResource,
            );
        }

        // Hydration mutates the loaded target in place (the in-memory witness returns the
        // stored instance by reference), so a mid-hydration throw or a post-hydration
        // validateEntity 422 would otherwise leave a partial write visible to reads. Wrap
        // the mutating tail in the persister's transaction so a failure rolls the store
        // back to its pre-hydration state — the in-memory analogue of Eloquent's
        // rollback-on-throw, keeping the two providers' failed-write semantics identical.
        $hydrator = $server->hydratorFor($type);
        $entity = $this->writeTransactionally($persister, function () use ($hydrator, $body, $resource, $type, $persister, $entity, $relationships): object {
            // The embedded associations apply on the loaded (keyed) parent BEFORE hydration,
            // each gated by the relation's request-aware `cannot*` flags in Mode::Replace —
            // the same `403` a PATCH …/relationships/{rel} would raise.
            $this->applyRelationships($persister, $type, $entity, $relationships, $body, false, flush: false);

            $entity = $hydrator->hydrate($this->withoutRelationships($body), $entity);
            \assert(\is_object($entity));

            if ($resource !== null) {
                $this->validator->validateEntity($resource, $entity, false);
            }

            return $persister->update($type, $entity);
        });

        // A write response honours `?include`, the Relationship Queries profile (windowed
        // linkage/pagination), and `?withCount` exactly as a read does — preload → window →
        // count over the updated entity, before rendering.
        $this->preloadIncludes($server, [$entity], $type, $request);
        $this->applyRelationshipWindows($server, $type, [$entity], $request);
        $this->applyRelationshipCounts($server, $type, [$entity], $request);

        return DataResponse::fromResource($entity, $serializer);
    }

    /**
     * `DELETE /{type}/{id}` — delete a single resource. A missing target is a `404`
     * (returned, not thrown); the loaded target is removed and the response is `204`
     * with no body.
     */
    private function delete(DeleteResourceOperation $operation): NoContentResponse|ErrorResponse
    {
        $type = $operation->target()->type;
        $id = $operation->target()->id;

        $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        // The `delete` policy gate authorizes the loaded target here, before it is
        // removed.
        $this->authorizer->authorize($type, Operation::Delete, $entity);

        $this->persisters->forType($type)->delete($type, $entity);

        return NoContentResponse::create();
    }

    /**
     * `PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{relationship}` — the relationship
     * mutation arms (full-replacement / add / remove), one shape (the Laravel twin of the
     * bundle's `mutateRelationship`, re-idiomised to a policy gate + the Eloquent persister):
     *  1. load the parent through the read provider (a `404` when absent);
     *  2. resolve the named relation (a `404` {@see RelationshipNotExists} when unknown or its
     *     relationship endpoint is suppressed);
     *  3. cardinality gate — an add/remove to a to-one is a `400`
     *     {@see RelationshipTypeInappropriate};
     *  4. parse the linkage with core's relationship-endpoint body parser;
     *  5. {@see guardMutability()} — the relation's request-aware mutability flags
     *     ({@see FullReplacementProhibited}/{@see AdditionProhibited}/{@see RemovalProhibited},
     *     `403`);
     *  6. validate the linkage — a `409` resource-type conflict / `422` malformed linkage;
     *  7. {@see gateMutate()} — the policy gate (PLAN decision 7): the parent's `update`
     *     policy, or the relation's `securityMutate` ability override;
     *  8. apply through the persister's storage-owning relationship seam (transactional per
     *     mutation), and render the resulting linkage ({@see IdentifierResponse::forRelationship()},
     *     `200` — the spec allows `200`/`204`; the bundle returns `200`, matched here).
     *
     * Lifecycle events + the atomic post-commit hook are a later phase (the handler has no
     * dispatcher yet); the windowed linkage + pivot `meta.pivot` echo ride the Relationship
     * Queries profile / pivot machinery.
     */
    private function mutateRelationship(
        AddToRelationshipOperation|UpdateRelationshipOperation|RemoveFromRelationshipOperation $operation,
        JsonApiRequestInterface $body,
        Mode $mode,
    ): IdentifierResponse|ErrorResponse {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $this->resolveRelation($server, $type, $relationshipName);
        if ($relation === null || !$relation->exposesRelationshipEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // Add / remove are to-many operations: a POST / DELETE to a to-one relationship
        // endpoint is a cardinality error (400).
        if ($mode !== Mode::Replace && $relation->isToMany() === false) {
            throw new RelationshipTypeInappropriate($relationshipName, 'to-one', 'to-many');
        }

        $linkage = $relation->isToMany()
            ? $body->getRelationshipDataToMany($relationshipName)
            : $body->getRelationshipDataToOne($relationshipName);

        // The relation's request-aware mutability flags (cannotReplace/cannotAdd/cannotRemove)
        // — a 403 family, distinct from the policy gate below (core ADR 0079).
        $this->guardMutability($relation, $relationshipName, $linkage, $mode, $body, $parent);

        // A pivot relation folds its stored pivot rows in for the merge-before-validate pass:
        // a member already in the relationship validates its MERGED pivot (stored row overlaid
        // by the incoming meta) in the update context, a genuinely-new member its incoming meta
        // in the create context. Eloquent supplies the real map; the in-memory witness returns
        // none (every member is then new). A Remove carries no pivot meta.
        $existingPivot = $mode !== Mode::Remove && $relation instanceof BelongsToMany && $relation->pivotFields() !== []
            ? $this->providers->forType($type)->fetchRelationshipPivot($type, $parent, $relation)
            : [];

        // A linkage carrying an unacceptable resource type is a 409 (the linkage twin of a
        // create's wrong data.type); a malformed linkage id / pivot meta a 422 — validated
        // before the persister applies, so a bad linkage never reaches storage. The endpoint
        // surface leaves `embeddedRelationName` null (pointers root at `/data/…`); the resolver
        // enables the linkage-id-format pass.
        $this->validator->validateRelationshipLinkage($relation, $linkage, $mode, $existingPivot, $body, resolveResource: $this->resourceResolver($server));

        // The policy gate (PLAN decision 7, NEW vs the bundle's event gate): a relationship
        // mutation authorizes the parent — `update` by default, or the relation's own
        // `securityMutate` ability override — after validation (422/409 before the policy 403).
        $this->gateMutate($type, $parent, $relation);

        $parent = $this->persisters->forType($type)->mutateRelationship($type, $parent, $relation, $linkage, $mode);

        // The 200 linkage echo carries `meta.pivot` for a pivot relation (Eloquent-only),
        // exactly as the relationship-read endpoint does.
        return IdentifierResponse::forRelationship(
            $parent,
            $this->relationshipLinkageSerializer($server, $type, $parent, $relation, $relationshipName),
            $relationshipName,
        );
    }

    /**
     * Enforces the relation's request-aware mutability flags for the requested mutation,
     * throwing core's typed `403`s (core ADR 0079 — each gate resolves its `cannotReplace/
     * cannotAdd/cannotRemove` closure against the inbound `$body` + `$parent`, so "only admins
     * may replace this relationship" is enforced HERE, distinct from the policy gate):
     *  - a to-one clear (`data: null`) is a removal ({@see RemovalProhibited}); a non-null
     *    to-one `PATCH` is a replacement ({@see FullReplacementProhibited});
     *  - a to-many `PATCH` is a replacement ({@see FullReplacementProhibited}); a `POST` add
     *    is gated by `allowsAddFor` ({@see AdditionProhibited}); a `DELETE` remove by
     *    `allowsRemoveFor` ({@see RemovalProhibited}).
     */
    private function guardMutability(
        RelationInterface $relation,
        string $relationshipName,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        JsonApiRequestInterface $body,
        object $parent,
    ): void {
        if ($linkage instanceof ToOneRelationship) {
            if ($linkage->isEmpty()) {
                if ($relation->allowsRemoveFor($body, $parent) === false) {
                    throw new RemovalProhibited($relationshipName);
                }

                return;
            }

            if ($relation->allowsReplaceFor($body, $parent) === false) {
                throw new FullReplacementProhibited($relationshipName);
            }

            return;
        }

        if ($mode === Mode::Replace && $relation->allowsReplaceFor($body, $parent) === false) {
            throw new FullReplacementProhibited($relationshipName);
        }

        if ($mode === Mode::Add && $relation->allowsAddFor($body, $parent) === false) {
            throw new AdditionProhibited($relationshipName);
        }

        if ($mode === Mode::Remove && $relation->allowsRemoveFor($body, $parent) === false) {
            throw new RemovalProhibited($relationshipName);
        }
    }

    /**
     * Gates a relationship mutation (PLAN decision 7): the parent's `update` policy
     * authorizes the loaded parent, UNLESS the relation declares its own mutate security —
     * a `false` opts the relation out of any mutate gate, a string overrides the ability the
     * parent is authorized against (the `securityMutate` twin of {@see gateRead()}'s
     * `securityRead`). A denial throws {@see \Illuminate\Auth\Access\AuthorizationException}
     * (rendered `403`).
     */
    private function gateMutate(string $type, object $parent, RelationInterface $relation): void
    {
        $mutate = $relation->securityMutate();
        if ($mutate === false) {
            return;
        }

        if (\is_string($mutate)) {
            $this->authorizer->authorizeAbility($type, $mutate, $parent);

            return;
        }

        $this->authorizer->authorize($type, Operation::Update, $parent);
    }

    /**
     * Collects the writable relationships named in a whole-resource write body's
     * `data.relationships`, resolved to their declared relation + parsed linkage. A
     * relationship that is unknown, or read-only for this operation, is skipped — the
     * read-only gate is request-aware (core ADR 0079) and mirrors core's own
     * `AbstractResource::hydrateRelationships()`, which never sees these because the
     * handler strips relationships from the body before core hydrates.
     *
     * @return list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>
     */
    private function extractRelationships(Server $server, string $type, JsonApiRequestInterface $body, bool $creating): array
    {
        $collected = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $this->resolveRelation($server, $type, $name);
            if ($relation === null || $relation->isReadOnlyFor($creating, $body)) {
                continue;
            }

            if ($relation->isToMany()) {
                if ($body->hasToManyRelationship($name)) {
                    $collected[] = ['relation' => $relation, 'linkage' => $body->getToManyRelationship($name)];
                }

                continue;
            }

            if ($body->hasToOneRelationship($name)) {
                $collected[] = ['relation' => $relation, 'linkage' => $body->getToOneRelationship($name)];
            }
        }

        return $collected;
    }

    /**
     * Applies each collected relationship to the entity through the persister's
     * relationship seam in {@see Mode::Replace}. An embedded relationship is a FULL
     * replacement of the named association, so on an UPDATE each one is gated by
     * {@see guardMutability()} in Mode::Replace (the same gate the `/relationships/{rel}`
     * endpoint applies, so a `cannotReplace(fn)` relation embedded in a PATCH raises the
     * same typed `403`); the gate is SKIPPED on a create (a create sets the initial state,
     * there is nothing to replace).
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     */
    private function applyRelationships(
        DataPersisterInterface $persister,
        string $type,
        object $entity,
        array $relationships,
        JsonApiRequestInterface $body,
        bool $creating,
        bool $flush,
    ): void {
        foreach ($relationships as $relationship) {
            if ($creating === false) {
                $this->guardMutability($relationship['relation'], $relationship['relation']->name(), $relationship['linkage'], Mode::Replace, $body, $entity);
            }

            $persister->mutateRelationship($type, $entity, $relationship['relation'], $relationship['linkage'], Mode::Replace, flush: $flush);
        }
    }

    /**
     * Splits collected relationships into `[beforeCreate, deferred]` by STORAGE SIDE, not
     * cardinality (docs/adr/0009). Only an **owner-side FK** relation — a {@see BelongsTo}
     * (but NOT its {@see HasOne} subclass) or a {@see MorphTo} — carries its foreign key on the
     * parent row, so it can be applied before the create and committed by the create's own
     * insert. Every OTHER relation is an inverse FK on the related rows (a {@see HasOne}/
     * {@see HasMany}) or a join table ({@see BelongsToMany}/{@see MorphToMany}) whose write
     * needs the parent's primary key, so it is DEFERRED to after `create()` returns the keyed
     * parent. Partitioning a `HasOne` (an inverse-FK to-one) by cardinality would land it in
     * the pre-create bucket and save the related row with a NULL foreign key on the not-yet-keyed
     * parent — silently dropping the association (or 500ing a NOT-NULL FK); the storage-side
     * split closes that.
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     *
     * @return array{0: list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>, 1: list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>}
     */
    private function partitionForCreateOrder(array $relationships): array
    {
        $beforeCreate = [];
        $deferred = [];
        foreach ($relationships as $relationship) {
            if ($this->appliesBeforeCreate($relationship['relation'])) {
                $beforeCreate[] = $relationship;

                continue;
            }

            $deferred[] = $relationship;
        }

        return [$beforeCreate, $deferred];
    }

    /**
     * Whether a relation stores its foreign key on the PARENT row (an owner-side to-one), so
     * it may be applied inline before `create()`. True only for a {@see BelongsTo} that is not
     * a {@see HasOne} (core's {@see HasOne} extends {@see BelongsTo}, but is an INVERSE FK on
     * the related row) or a {@see MorphTo} (an owner-side polymorphic FK). Everything else —
     * inverse-FK to-ones/to-manys and join tables — must be deferred to after the parent is
     * keyed.
     */
    private function appliesBeforeCreate(RelationInterface $relation): bool
    {
        return ($relation instanceof BelongsTo && !$relation instanceof HasOne)
            || $relation instanceof MorphTo;
    }

    /**
     * The relationship names present in the write body's `data.relationships` member, or
     * an empty list when the document carries none.
     *
     * @return list<string>
     */
    private function bodyRelationshipNames(JsonApiRequestInterface $body): array
    {
        $data = $body->getResource();
        if (!\is_array($data)) {
            return [];
        }

        $relationships = $data['relationships'] ?? null;
        if (!\is_array($relationships)) {
            return [];
        }

        /** @var list<string> $names */
        $names = \array_values(\array_filter(\array_keys($relationships), '\is_string'));

        return $names;
    }

    /**
     * A copy of the write body with `data.relationships` removed, so core's hydrator
     * hydrates only the id + attributes and never assigns a scalar linkage id to a typed
     * association property — the associations apply through the persister seam instead
     * (bundle ADR 0018).
     */
    private function withoutRelationships(JsonApiRequestInterface $body): JsonApiRequestInterface
    {
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if (!\is_array($data) || !isset($data['relationships'])) {
            return $body;
        }

        unset($data['relationships']);
        $document['data'] = $data;

        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return $stripped;
    }

    /**
     * The existing pivot rows of each `belongsToMany` pivot relation carried in the write
     * body, keyed by relation name then related id — read off the loaded parent through
     * {@see DataProviderInterface::fetchRelationshipPivot()}. The validator folds these
     * under the incoming linkage meta per member for the merge-before-validate pass (an
     * existing member validates its merged pivot in the update context, a new member its
     * incoming meta in create context). A non-pivot relation, or a provider storing no
     * pivot, contributes nothing.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function existingPivots(Server $server, string $type, JsonApiRequestInterface $body, object $parent): array
    {
        $provider = $this->providers->forType($type);

        $existingPivots = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $this->resolveRelation($server, $type, $name);
            if (!$relation instanceof BelongsToMany || $relation->pivotFields() === []) {
                continue;
            }

            $pivot = $provider->fetchRelationshipPivot($type, $parent, $relation);
            if ($pivot !== []) {
                $existingPivots[$name] = $pivot;
            }
        }

        return $existingPivots;
    }

    /**
     * Runs `$work` inside the persister's transaction when it is
     * {@see TransactionalDataPersisterInterface} — a rollback boundary around the mutating
     * write tail (hydrate → validateEntity → commit) so a post-hydration failure discards
     * the partial write rather than leaking it. A persister that cannot transact runs
     * `$work` directly (it owns its own atomicity). A throwable rolls the transaction back
     * and re-propagates unchanged (rendered by the exception renderer).
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function writeTransactionally(DataPersisterInterface $persister, \Closure $work): mixed
    {
        if (!$persister instanceof TransactionalDataPersisterInterface) {
            return $work();
        }

        $persister->beginTransaction();

        try {
            $result = $work();
        } catch (\Throwable $throwable) {
            $persister->rollback();

            throw $throwable;
        }

        $persister->commit();

        return $result;
    }

    /**
     * The originating JSON:API request from the operation context, asserted non-null: a
     * write operation is always dispatched from an HTTP request through the controller,
     * so the context always carries one.
     */
    private function jsonApiRequest(OperationContext $context): JsonApiRequestInterface
    {
        $request = $context->httpRequest();
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * Whether the client applied a filter the resource declares
     * {@see SupportsSingular singular} — the trigger to collapse the collection to a
     * zero-to-one ({@see DataResponse::fromResource()}) response.
     *
     * @param list<FilterInterface> $filters
     */
    private function appliesSingularFilter(array $filters, QueryParameters $queryParameters): bool
    {
        foreach ($filters as $filter) {
            if ($filter instanceof SupportsSingular
                && $filter->isSingular()
                && \array_key_exists($filter->key(), $queryParameters->filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Materializes the result's items once, as a list, so they can be both peeked
     * (the singular first match) and rendered without consuming a one-shot iterator.
     *
     * @param CollectionResult<object> $result
     *
     * @return list<object>
     */
    private function materialize(CollectionResult $result): array
    {
        return \is_array($result->items)
            ? \array_values($result->items)
            : \iterator_to_array($result->items, false);
    }
}
