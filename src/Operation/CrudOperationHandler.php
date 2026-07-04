<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\SupportsSingular;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Server\RequestBaseUri;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\Authorizer;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
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
 * The document-semantic validation (the always-on illuminate/validation bridge) and the
 * policy authorization gate hook into the create/update/delete arms at the marked seams in
 * the follow-on Phase 2 work; `?include`/`?withCount` batching, lifecycle events + hooks,
 * relationship writes and the relationship read endpoints are later phases — every
 * unhandled operation falls to the default arm, which returns a `404`
 * {@see ResourceNotFound} exactly like the bundle's `CrudOperationHandler` default arm
 * (never a `500`).
 */
final class CrudOperationHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly ResourceValidator $validator,
        private readonly FilterValueValidator $filterValidator,
        private readonly Authorizer $authorizer,
    ) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|NoContentResponse|ErrorResponse
    {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            $operation instanceof CreateResourceOperation => $this->create($operation),
            $operation instanceof UpdateResourceOperation => $this->update($operation),
            $operation instanceof DeleteResourceOperation => $this->delete($operation),
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

        // Core's hydrator applies the id policy (client vs server-generated; a wrong
        // `type` 409s, a forbidden client id 403s) then allow-list-hydrates the
        // attributes; the throws propagate to the exception renderer.
        $entity = $server->hydratorFor($type)->hydrate($body, $entity);
        \assert(\is_object($entity));

        // The post-hydration entity seam (uniqueness's custom cousin): a resource's
        // EntityConstraintInterface constraints validate the hydrated entity before
        // persist. A no-op for a resource that declares none (UniqueEntity is folded into
        // the pre-hydration Rule::unique above).
        if ($resource !== null) {
            $this->validator->validateEntity($resource, $entity, true);
        }

        // The persister commits and returns the entity with any store-generated id
        // populated (Eloquent auto-increment / the in-memory store's minted id).
        $entity = $persister->create($type, $entity);

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

        // Hydration mutates the loaded target in place (the in-memory witness returns the
        // stored instance by reference), so a mid-hydration throw or a post-hydration
        // validateEntity 422 would otherwise leave a partial write visible to reads. Wrap
        // the mutating tail in the persister's transaction so a failure rolls the store
        // back to its pre-hydration state — the in-memory analogue of Eloquent's
        // rollback-on-throw, keeping the two providers' failed-write semantics identical.
        $hydrator = $server->hydratorFor($type);
        $entity = $this->writeTransactionally($persister, function () use ($hydrator, $body, $resource, $type, $persister, $entity): object {
            $entity = $hydrator->hydrate($body, $entity);
            \assert(\is_object($entity));

            if ($resource !== null) {
                $this->validator->validateEntity($resource, $entity, false);
            }

            return $persister->update($type, $entity);
        });

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
