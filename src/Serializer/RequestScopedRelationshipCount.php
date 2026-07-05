<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;

/**
 * A stable, swappable holder for the per-request `?withCount` count seam. Core's
 * {@see RelationshipCountInterface} is injected into the immutable, memoized
 * {@see \haddowg\JsonApi\Server\Server} once (at {@see \haddowg\JsonApiLaravel\Server\ServerFactory}
 * time); but the actual counts are per request — they depend on the fetched page — so
 * the value behind the seam must be swappable without rebuilding the Server.
 *
 * This holder is that indirection: the factory threads it through
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipCount()} once, and the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} swaps its
 * {@see BatchedRelationshipCount} backing in on each read — and clears it (sets `null`)
 * on a read that named no `?withCount` — so the render pass that follows
 * `Server::dispatch()` consults exactly the page just fetched and never a previous
 * request's counts. With no backing set it answers `null` and core omits `meta.total`,
 * exactly as if no seam were wired.
 *
 * The handler clears it (with the pagination and linkage holders) at the very start of
 * EVERY dispatch, so a long-lived worker (Octane/queue) reusing the singleton never
 * inherits a prior request's counts — the Laravel-side equivalent of the bundle twin's
 * `ResetInterface` (`kernel.reset`). No `scoped()` rebind is needed (or effective: the
 * memoized Server and the singleton handler capture the instance at construction).
 */
final class RequestScopedRelationshipCount implements RelationshipCountInterface
{
    private ?RelationshipCountInterface $delegate = null;

    /**
     * Installs (or clears, with `null`) the batched counts for the read currently being
     * handled, so the render that follows reads this page's counts. The handler calls it
     * on every read, so a read with no `?withCount` clears any counts a prior request
     * installed.
     */
    public function set(?RelationshipCountInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function countRelationship(mixed $model, RelationInterface $relation): ?int
    {
        return $this->delegate?->countRelationship($model, $relation);
    }
}
