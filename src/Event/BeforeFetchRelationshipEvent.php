<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched before a **relationship-linkage** read renders
 * (`GET /{type}/{id}/relationships/{rel}`) — the linkage twin of
 * {@see BeforeFetchRelatedEvent}. It carries the loaded {@see $parent} and the
 * {@see $relation}; a listener that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} (or an
 * {@see \Illuminate\Auth\Access\AuthorizationException}, rendered `403`) aborts the
 * read.
 *
 * Read authorization is enforced by the policy-first
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} (PLAN decision 7), so this
 * event is a pure lifecycle seam and is **not** routed to the resource hook trait.
 */
final class BeforeFetchRelationshipEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly string $serverName,
    ) {}
}
