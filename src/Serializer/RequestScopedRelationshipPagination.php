<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;

/**
 * A stable, swappable holder for core's {@see RelationshipPaginationInterface} seam under
 * the Relationship Queries profile (the Laravel twin of the Symfony bundle's
 * `RequestScopedRelationshipPagination`). Core consults the seam for EVERY rendered to-many
 * relation to learn the page-1 pagination state it emits as the relationship object's
 * `first`/`prev`/`next` (+`last`) links — but the page is per request (it depends on the
 * profile's per-relationship sort/filter and the fetched parents), so the value behind the
 * seam must be swappable without rebuilding the memoized {@see \haddowg\JsonApi\Server\Server}.
 *
 * This holder is that indirection, mirroring {@see RequestScopedRelationshipCount}: the
 * {@see \haddowg\JsonApiLaravel\Server\ServerFactory} threads it through core's
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipPagination()} once, and the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} swaps a
 * {@see WindowedRelationshipPagination} backing in on each read whose request negotiated the
 * profile — and clears it (`null`) otherwise — so the render pass that follows
 * {@see \haddowg\JsonApi\Server\Server::dispatch()} reads exactly the page just windowed and
 * never a previous request's. With no backing set it answers `null` for every relation, so
 * core emits no relationship-object pagination links (the profile-not-negotiated default).
 *
 * The handler clears it (with the linkage and count holders) at the very start of EVERY
 * dispatch, so a long-lived worker (Octane/queue) reusing the singleton never inherits a
 * prior message's windowed pages — the Laravel-side equivalent of the bundle's
 * `kernel.reset` (the Symfony `ResetInterface` is dropped, per PLAN decision 9's Octane
 * follow-up note).
 */
final class RequestScopedRelationshipPagination implements RelationshipPaginationInterface
{
    private ?RelationshipPaginationInterface $delegate = null;

    /**
     * Installs (or clears, with `null`) the windowed page-1 backing for the read currently
     * being handled, so the render that follows reads this request's relationship pages. The
     * handler calls it on every read, so a read whose request did not negotiate the profile
     * clears any backing a prior request installed.
     */
    public function set(?RelationshipPaginationInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function paginateRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipPagination {
        return $this->delegate?->paginateRelationship($model, $relation, $request);
    }
}
