<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

/**
 * Dispatched by the {@see \haddowg\JsonApiLaravel\Action\ActionInvoker} *after*
 * entity resolution and input validation, and *before* the action's ability gate +
 * handler run (PLAN decision 12). It carries the action's {@see $type} and
 * {@see $action} name, the {@see $subject} — the resolved entity for a
 * resource-scope action, `null` for a collection-scope action — and the declared
 * Gate {@see $ability} (or `null` for an unsecured action).
 *
 * Unlike the bundle, the ability is enforced directly by the invoker via the
 * package {@see \haddowg\JsonApiLaravel\Authorization\Authorizer} (parallel to how
 * the CRUD arms enforce policies inline), so this event is a pure lifecycle seam: a
 * listener may throw a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * to abort before the handler. It is **not** routed to the resource hook trait.
 */
final class BeforeActionEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $action,
        public readonly ?object $subject,
        public readonly ?string $ability,
    ) {}
}
