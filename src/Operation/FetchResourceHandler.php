<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\CursorCollectionResult;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\SupportsSingular;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * The Phase 1 read operation handler, wired via `Server::withHandler()` so
 * `Server::dispatch()` has a target. It implements the read arm — the two fetch
 * surfaces `GET /{type}` and `GET /{type}/{id}` — delegating to the per-type
 * {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface} resolved from the
 * registry.
 *
 * A single fetch maps a missing resource to a JSON:API `404` (the handler RETURNS an
 * {@see ErrorResponse}, it does not throw). A collection fetch resolves the
 * resource's declared filter/sort vocabularies, default sort and pagination strategy
 * into a {@see CollectionCriteria}, asks the provider to execute it, and renders the
 * G21 §6a matrix: a **singular** filter collapses to a zero-to-one resource; a
 * **cursor** (keyset) page renders through the paginator's `fromBoundaries()` path
 * carrying the provider-minted tokens; a **counted** page renders `meta.page.total` +
 * `links.last` and echoes `meta.total`; a **count-free** page renders self/first/prev/
 * next with `next` driven by `hasMore` and no total; a **fetch-all** (no paginator)
 * renders `meta.total` unconditionally.
 *
 * `?include`/`?withCount` batching, lifecycle events + hooks, filter-value 400s (the
 * always-on validator bridge), and the write arms are later phases — every non-fetch
 * operation falls to the default arm, which returns a `404` {@see ResourceNotFound}
 * exactly like the bundle's `CrudOperationHandler` default arm (never a `500`). It
 * grows the create/update/delete arms in Phase 2.
 */
final class FetchResourceHandler implements OperationHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|ErrorResponse
    {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
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

            return DataResponse::fromResource($model, $serializer);
        }

        // A bare serializer/hydrator pair declares no field inventory, so it has no
        // filter/sort vocabulary and no resource-level paginator.
        $resource = $server->hasResourceFor($type) ? $server->resourceFor($type) : null;

        $filters = $resource?->filters() ?? [];

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
