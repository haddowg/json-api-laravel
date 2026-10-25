<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\ViewableRows;
use haddowg\JsonApiLaravel\DataProvider\ScopedToOneTargets;

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
 * It carries a second backing, {@see scope()}: the members of every rendered to-many whose
 * related type is served by its own scoping provider, loaded through that provider by the
 * {@see \haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher}. A windowed page wins
 * over it, since the window is itself fetched through the same provider.
 *
 * Every to-one target it hands core leaves out a related row the caller may not `view`
 * ({@see ViewableRows}, ADR 0045), so it is missing from `data` and from `included`. A to-many
 * shows exactly what its query returns; a related type the caller may not list is withheld
 * whole by the relation authorizer instead.
 *
 * The handler clears both (with the pagination and count holders) at the very start of EVERY
 * dispatch, so a long-lived worker reusing the singleton never inherits a prior message's
 * windowed linkage — the Laravel-side equivalent of the bundle's `kernel.reset`.
 */
final class RequestScopedRelationshipLinkage implements ToOneRelationshipLinkageInterface
{
    private ?RelationshipLinkageInterface $delegate = null;

    /**
     * @var list<RelationshipLinkageInterface>
     */
    private array $scoped = [];

    public function __construct(
        private readonly ScopedToOneTargets $toOne,
        private readonly ViewableRows $rows,
    ) {}

    /**
     * Starts an operation on `$server`, named `$serverName`: drops both backings and every
     * to-one answer.
     */
    public function bind(?Server $server, ?string $serverName = null): void
    {
        $this->delegate = null;
        $this->scoped = [];
        $this->toOne->bind($server);
        $this->rows->bind($server, $serverName);
    }

    /**
     * Drops the scoped backing and every to-one answer before a render's preload, so a target a
     * write just changed is decided again and the preload walks what the render will show.
     */
    public function resetScope(): void
    {
        $this->scoped = [];
        $this->toOne->forget();
        $this->rows->forget();
    }

    /**
     * The object to-one `$relation` renders on `$model`, for the to-one related endpoint.
     */
    public function toOneTargetFor(object $model, RelationInterface $relation, JsonApiRequestInterface $request): mixed
    {
        $permission = $this->rows->relationPermission($model, $relation);
        if ($permission === false) {
            return null;
        }

        $target = $this->toOne->targetFor($model, $relation, $request);

        return $permission === null && \is_object($target) && !$this->rows->allows($target, $relation) ? null : $target;
    }

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

    /**
     * Installs (or clears, with `null`) the scoped-provider linkage for the read currently
     * being handled.
     */
    public function scope(?RelationshipLinkageInterface $scoped): void
    {
        $this->scoped = $scoped === null ? [] : [$scoped];
    }

    /**
     * Adds scoped-provider linkage for further parents of the same read (the members of a
     * windowed page, which are not the objects the include preload walked).
     */
    public function addScope(RelationshipLinkageInterface $scoped): void
    {
        $this->scoped[] = $scoped;
    }

    public function linkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?RelationshipLinkage {
        $linkage = $this->delegate?->linkageForRelationship($model, $relation, $request);

        foreach ($this->scoped as $scoped) {
            $linkage ??= $scoped->linkageForRelationship($model, $relation, $request);
        }

        return $linkage;
    }

    public function toOneLinkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?ToOneRelationshipLinkage {
        // A permission the relation declares replaces the target type's `view` (ADR 0049).
        $permission = \is_object($model) ? $this->rows->relationPermission($model, $relation) : null;
        if ($permission === false) {
            return ToOneRelationshipLinkage::none();
        }

        $linkage = $this->toOne->linkageFor($model, $relation, $request);
        if ($permission === true) {
            return $linkage;
        }

        $target = $linkage !== null ? $linkage->target : $relation->readValue($model, $request);

        return \is_object($target) && !$this->rows->allows($target, $relation) ? ToOneRelationshipLinkage::none() : $linkage;
    }
}
