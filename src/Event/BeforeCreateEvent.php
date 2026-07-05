<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before a create persists, after the aggregate {@see BeforeSaveEvent}.
 * The {@see $entity} is **mutable** (a set field is persisted); a listener that
 * throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts the
 * create before any commit. Routed to
 * {@see \haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface::beforeCreate()}.
 */
final class BeforeCreateEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}
}
