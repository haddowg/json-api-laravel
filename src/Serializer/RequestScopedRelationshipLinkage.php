<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;

/**
 * A stable, swappable holder for core's {@see RelationshipLinkageInterface} seam under the
 * Relationship Queries profile (the Laravel twin of the Symfony bundle's
 * `RequestScopedRelationshipLinkage`). Core consults the seam for every rendered to-many
 * relation to learn whether its linkage `data` is supplied out-of-band (the windowed page)
 * rather than read off the parent property — but the page is per request (it depends on the
 * profile's per-relationship sort/filter and the fetched parents), so the value behind the
 * seam must be swappable without rebuilding the memoized {@see \haddowg\JsonApi\Server\Server}.
 *
 * This holder is that indirection, mirroring {@see RequestScopedRelationshipPagination}: the
 * {@see \haddowg\JsonApiLaravel\Server\ServerFactory} threads it through core's
 * {@see \haddowg\JsonApi\Server\Server::withRelationshipLinkage()} once, and the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} swaps a
 * {@see WindowedRelationshipLinkage} backing in on each read whose request negotiated the
 * profile — and clears it (`null`) otherwise — so the render pass reads exactly the page
 * just windowed and never a previous request's. With no backing set it answers `null` for
 * every relation, so core reads linkage off the model (the profile-not-negotiated default).
 *
 * The handler clears it (with the pagination and count holders) at the very start of EVERY
 * dispatch, so a long-lived worker reusing the singleton never inherits a prior message's
 * windowed linkage — the Laravel-side equivalent of the bundle's `kernel.reset`.
 */
final class RequestScopedRelationshipLinkage implements RelationshipLinkageInterface
{
    private ?RelationshipLinkageInterface $delegate = null;

    /**
     * Installs (or clears, with `null`) the windowed linkage backing for the read currently
     * being handled, so the render that follows reads this request's windowed relationship
     * linkage. The handler calls it on every read, so a read whose request did not negotiate
     * the profile clears any backing a prior request installed.
     */
    public function set(?RelationshipLinkageInterface $delegate): void
    {
        $this->delegate = $delegate;
    }

    public function linkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipLinkage {
        return $this->delegate?->linkageForRelationship($model, $relation, $request);
    }
}
