<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

/**
 * Dispatched by the {@see \haddowg\JsonApiLaravel\Action\ActionInvoker} *after* the
 * action handler has run, for symmetry with the CRUD after-hooks. It carries the
 * action's {@see $type} and {@see $action} name and the {@see $subject} — the
 * resolved entity for a resource-scope action, `null` for a collection-scope
 * action.
 *
 * A custom action is never part of an Atomic Operations batch, so this event always
 * fires inline. It is a pure lifecycle seam (an `Event::listen` seam), **not** routed
 * to the resource hook trait.
 */
final class AfterActionEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $action,
        public readonly ?object $subject,
    ) {}
}
