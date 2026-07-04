<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * The Phase 0 operation handler wired via `Server::withHandler()` so
 * `Server::dispatch()` has a target. It implements only the read arm — the two
 * fetch surfaces `GET /{type}` and `GET /{type}/{id}` — delegating to the per-type
 * {@see \haddowg\JsonApiLaravel\DataProvider\DataProviderInterface} resolved from the
 * registry.
 *
 * A single fetch maps a missing resource to a JSON:API `404` (the handler RETURNS an
 * {@see ErrorResponse}, it does not throw); a collection fetch executes an empty
 * {@see CollectionCriteria} (no filters/sort/pagination in this phase) and renders a
 * plain collection. Filter/sort/pagination, writes, and the relationship surfaces are
 * later phases — every non-fetch operation falls to the default arm, which returns a
 * `404` {@see ResourceNotFound} exactly like the bundle's `CrudOperationHandler`
 * default arm (never a `500`, which would misattribute a not-yet-implemented operation
 * to a server fault). The read-only workbench never routes a write, so this arm is
 * unreached in-tree; it grows the create/update/delete arms in Phase 2.
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
        $type = $operation->target()->type;
        $provider = $this->providers->forType($type);
        $serializer = $server->serializerFor($type);

        $id = $operation->target()->id;
        if ($id !== null) {
            $model = $provider->fetchOne($type, $id);
            if ($model === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            return DataResponse::fromResource($model, $serializer);
        }

        $result = $provider->fetchCollection($type, new CollectionCriteria($operation->queryParameters()));
        $items = \is_array($result->items)
            ? \array_values($result->items)
            : \iterator_to_array($result->items, false);

        return DataResponse::fromCollection($items, $serializer);
    }
}
