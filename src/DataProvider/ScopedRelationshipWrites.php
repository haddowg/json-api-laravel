<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Server\Server;

/**
 * Applies a related type's own scoping provider (see {@see DataProviderRegistry::scopingProviderFor()})
 * to a relationship write, the write twin of {@see ScopedToOneTargets}. A persister resolves
 * linkage ids against its storage and never asks a provider, so without this a write could
 * attach, replace or remove a row that provider never serves.
 *
 * Every id the client names must come back from that provider's `fetchOne()`, the read the
 * type's own endpoint makes; one that doesn't is a `404`, whether the row is hidden or absent.
 * A to-many replace also keeps the members the client cannot see: they are added back to the
 * linkage the persister applies, so the replace changes only the visible members. Types served
 * by a reference provider (Eloquent, in-memory) are left to the persister, as before.
 */
final class ScopedRelationshipWrites
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    /**
     * Throws {@see ResourceNotFound} when an id in `$linkage` names a row its type's scoping
     * provider does not return.
     *
     * @throws ResourceNotFound
     */
    public function assertVisible(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage): void
    {
        foreach ($this->identifiers($linkage) as $identifier) {
            if ($identifier->id === null) {
                continue;
            }

            $type = $this->typeOf($relation, $identifier);
            $provider = $this->providers->scopingProviderFor($type);
            if ($provider !== null && $provider->fetchOne($type, $identifier->id) === null) {
                throw new ResourceNotFound();
            }
        }
    }

    /**
     * The linkage a to-many replace on `$parent` applies: the client's members plus every current
     * member its type's scoping provider hides, so those are left in place. Returns `$linkage`
     * itself when the relation reaches no scoped type or no current member is hidden.
     */
    public function withHiddenMembers(
        object $parent,
        RelationInterface $relation,
        ToManyRelationship $linkage,
        Server $server,
        JsonApiRequestInterface $request,
    ): ToManyRelationship {
        if (!$this->relatesToScopingProvider($relation)) {
            return $linkage;
        }

        // Read off a copy, so a relation loaded by the read (an Eloquent model caches it) never
        // stays on the parent the response renders.
        $current = $relation->readValue(clone $parent, $request);
        if (!\is_iterable($current)) {
            return $linkage;
        }

        $hidden = [];
        foreach ($current as $member) {
            if (!\is_object($member)) {
                continue;
            }

            $serializer = $relation->resolveSerializer($member, $server);
            if ($serializer === null) {
                continue;
            }

            $type = $serializer->getType($member);
            $provider = $this->providers->scopingProviderFor($type);
            $id = $serializer->getId($member);
            if ($provider !== null && $provider->fetchOne($type, $id) === null) {
                $hidden[] = new ResourceIdentifier($type, $id);
            }
        }

        return $hidden === [] ? $linkage : new ToManyRelationship([...$linkage->resourceIdentifiers, ...$hidden]);
    }

    /**
     * @return list<ResourceIdentifier>
     */
    private function identifiers(ToOneRelationship|ToManyRelationship $linkage): array
    {
        if ($linkage instanceof ToManyRelationship) {
            return \array_values($linkage->resourceIdentifiers);
        }

        return $linkage->resourceIdentifier !== null ? [$linkage->resourceIdentifier] : [];
    }

    private function typeOf(RelationInterface $relation, ResourceIdentifier $identifier): string
    {
        return $identifier->type !== '' ? $identifier->type : ($relation->relatedTypes()[0] ?? '');
    }

    private function relatesToScopingProvider(RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $type) {
            if ($this->providers->scopingProviderFor($type) !== null) {
                return true;
            }
        }

        return false;
    }
}
