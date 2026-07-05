<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before a delete. The {@see $entity} is the loaded target; a listener
 * that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts
 * the delete before it runs — the natural place for a delete guard (a `409` when
 * the resource is still referenced). Routed to
 * {@see \haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface::beforeDelete()}.
 */
final class BeforeDeleteEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}
}
