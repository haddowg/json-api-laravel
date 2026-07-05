<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before a collection is fetched (`GET /{type}`), ahead of the provider
 * query. A collection has no single subject, so — unlike {@see AfterFetchOneEvent} —
 * there is no entity; the event carries only the type, request, and server.
 *
 * A listener that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * (or an {@see \Illuminate\Auth\Access\AuthorizationException}, rendered `403`) aborts
 * the read **before the query runs** — the natural place for an all-or-nothing
 * collection gate. Row-level read authorization still belongs in the provider query
 * scope; this gate blanket-blocks the whole collection for a user or role.
 *
 * The Laravel package authorizes CRUD reads through the policy-first
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} (PLAN decision 7), so this
 * event is a pure lifecycle seam — it is **not** routed to the resource hook trait.
 */
final class BeforeFetchCollectionEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly string $serverName,
    ) {}
}
