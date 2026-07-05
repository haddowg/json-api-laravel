<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before a create **or** an update persists — the aggregate write gate,
 * fired before the more specific {@see BeforeCreateEvent}/{@see BeforeUpdateEvent}
 * so a concern that applies to every write (audit stamps, tenant assignment) lives
 * in one place. {@see $creating} distinguishes create from update.
 *
 * The {@see $entity} is **mutable**: a listener may set fields on it (the change is
 * persisted by the subsequent flush). A listener that throws a
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts before the
 * persister runs (no commit happens). Routed to
 * {@see \haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface::beforeSave()}.
 */
final class BeforeSaveEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly bool $creating,
        public readonly string $serverName,
    ) {}
}
