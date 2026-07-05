<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched once per request, before the operation runs — the Laravel twin of the
 * bundle's `ServingEvent`, bridging core's server-level `serving` seam (core ADR
 * 0050) onto Laravel's event {@see \Illuminate\Contracts\Events\Dispatcher}. The
 * {@see \haddowg\JsonApiLaravel\Server\ServerFactory} registers a
 * `Server::withServing()` handler that dispatches this event inside
 * `Server::dispatch()`, so a core-direct consumer and a Laravel consumer share the
 * same request-wide gate.
 *
 * It is a **before**-only gate: a listener that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts the request
 * (the throw propagates out of the serving closure → out of `dispatch()` → the
 * route-scoped {@see \haddowg\JsonApiLaravel\Exception\JsonApiExceptionRenderer}),
 * so the operation never runs. It carries no response — request-wide response
 * shaping belongs to the per-operation after events.
 *
 * It is a server-level (not per-type) seam, so it is **not** routed to the resource
 * hook trait; it is an `Event::listen(ServingEvent::class, …)` seam only.
 */
final class ServingEvent
{
    public function __construct(
        public readonly JsonApiRequestInterface $request,
        public readonly string $serverName,
    ) {}
}
