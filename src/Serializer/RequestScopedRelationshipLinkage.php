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
 * Every linkage it hands core, and every to-one target, leaves out the related rows the caller
 * may not `view` ({@see ViewableRows}, ADR 0045), so they are missing from `data` and from
 * `included`. When nothing is left out it supplies what it would have without the check, so
 * core reads the model as before.
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

    private ?Server $server = null;

    public function __construct(
        private readonly ScopedToOneTargets $toOne,
        private readonly ViewableRows $rows,
    ) {}

    /**
     * Starts an operation on `$server`: drops both backings and every to-one answer.
     */
    public function bind(?Server $server): void
    {
        $this->server = $server;
        $this->delegate = null;
        $this->scoped = [];
        $this->toOne->bind($server);
        $this->rows->bind($server);
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
        $target = $this->toOne->targetFor($model, $relation, $request);

        return \is_object($target) && !$this->rows->allows($target, $relation) ? null : $target;
    }

    /**
     * The members of a related endpoint's page the caller may see.
     *
     * @param list<object> $items
     *
     * @return list<object>
     */
    public function viewableMembers(array $items, RelationInterface $relation): array
    {
        /** @var list<object>|null $kept */
        $kept = $this->rows->filter($items, $relation);

        return $kept ?? $items;
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

        if ($linkage !== null) {
            $kept = \is_iterable($linkage->data) ? $this->rows->filter($linkage->data, $relation) : null;

            return $kept === null ? $linkage : new RelationshipLinkage($kept);
        }

        // Read the members only when core is about to render them anyway, so a lazy relation
        // that isn't loaded stays unloaded.
        if (!\is_object($model) || !$relation->isToMany() || $this->defers($model, $relation)) {
            return null;
        }

        $members = $relation->readValue($model, $request);
        $kept = \is_iterable($members) ? $this->rows->filter($members, $relation) : null;

        return $kept === null ? null : new RelationshipLinkage($kept);
    }

    public function toOneLinkageForRelationship(
        mixed $model,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): ?ToOneRelationshipLinkage {
        $linkage = $this->toOne->linkageFor($model, $relation, $request);
        $target = $linkage !== null ? $linkage->target : $relation->readValue($model, $request);

        return \is_object($target) && !$this->rows->allows($target, $relation) ? ToOneRelationshipLinkage::none() : $linkage;
    }

    /**
     * Whether core leaves this relation's data unread on `$model`: a lazy relation the
     * storage hasn't loaded (the mirror of core's own deferral rule).
     */
    private function defers(object $model, RelationInterface $relation): bool
    {
        if (!$relation->emitsDataOnlyWhenLoaded() || !$relation->includesLinks()) {
            return false;
        }

        return $this->server?->resources()->relationshipLoadState()?->isRelationshipLoaded($model, $relation) === false;
    }
}
