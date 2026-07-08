<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

/**
 * An {@see ActionHandlerInterface} may also implement this to make its `asLink: true`
 * link **conditional on the rendered entity's state** — not just on the ability gate.
 *
 * `asLink` decides *whether* the action contributes a link; the ability gate hides it from
 * a requester who could not invoke it; this refines *when* it applies to a given resource.
 * The two are complementary and both must pass for the link to render: the ability answers
 * "may this user?" (a `403` on invocation), {@see shouldLink()} answers "is this resource in
 * a state the action applies to?" (a link that simply isn't offered; invoking anyway is the
 * handler's own concern — typically a `409`). The package's synthesized soft-delete
 * `restore` action implements this to render its link only on a **trashed** resource; the
 * same seam serves any state-scoped link (a `publish` link only on a draft, a `cancel` link
 * only on an open order).
 *
 * The predicate lives here — a method on the handler — rather than on the attribute, because
 * a closure is not a constant expression and so cannot be an attribute argument (the same
 * reason {@see \haddowg\JsonApi\Resource\SerializerInterface::getMeta()} and the self-applying
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\AppliesToEloquentQueryBuilder} filter
 * live on their objects). It is `route:cache`-safe: discovery records only a cheap boolean on
 * the descriptor, and the handler is resolved lazily at render time — and only for a
 * conditionally-linked action.
 */
interface ConditionallyLinked
{
    /**
     * Whether this action's `links` member should render for `$entity` (a rendered resource
     * of the action's mount type). Called only when the action is `asLink` and the requester
     * has already passed the ability gate.
     */
    public function shouldLink(object $entity): bool;
}
