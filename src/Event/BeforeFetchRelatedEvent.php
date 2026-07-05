<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched before a **related** read renders (`GET /{type}/{id}/{rel}`), carrying
 * the loaded {@see $parent} and the {@see $relation} so a listener can observe or
 * abort the relationship read independently of its parent. A listener that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} (or an
 * {@see \Illuminate\Auth\Access\AuthorizationException}, rendered `403`) aborts the
 * read.
 *
 * Read authorization itself is enforced by the policy-first
 * {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} (the parent's `view`
 * policy, or the relation's own `securityRead` ability override — PLAN decision 7),
 * so this event is a pure lifecycle seam and is **not** routed to the resource hook
 * trait.
 */
final class BeforeFetchRelatedEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly string $serverName,
    ) {}
}
