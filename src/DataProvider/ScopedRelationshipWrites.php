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
 * Checks the linkage a relationship write sends against each related type's provider, the
 * write twin of {@see ScopedToOneTargets}, before anything is stored.
 *
 * Every id the client names must be a row its type's provider serves, the read the type's own
 * endpoint makes; one that isn't is a `404`, whether the row is absent or a scoping provider
 * (see {@see DataProviderRegistry::scopingProviderFor()}) hides it. That holds on every mode:
 * a remove may name an existing resource that is not a member, which changes nothing, but not
 * one that does not exist. A type no provider serves has nothing to check against. Each type
 * is asked once per write, through {@see AdmitsIdsInterface} or {@see FetchesManyInterface}
 * where its provider offers either. A to-many replace also keeps the members the client cannot
 * see: they are added back to the linkage the persister applies, so the replace changes only
 * the visible members.
 */
final class ScopedRelationshipWrites
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly ?IdentifiedMorphMembers $identifiedMembers = null,
    ) {}

    /**
     * Throws {@see ResourceNotFound} when an id in `$linkage` names a row its type's provider
     * does not serve.
     *
     * @throws ResourceNotFound
     */
    public function assertVisible(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, ?Server $server = null): void
    {
        $byType = [];
        foreach ($this->identifiers($linkage) as $identifier) {
            if ($identifier->id !== null) {
                $byType[$this->typeOf($relation, $identifier)][$identifier->id] = $identifier->id;
            }
        }

        foreach ($byType as $type => $ids) {
            $type = (string) $type;
            if (!$this->providers->supportsType($type)) {
                continue;
            }

            $ids = \array_values(\array_map('strval', $ids));
            if (\count($this->served($this->providers->forType($type), $type, $ids, $server)) !== \count($ids)) {
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
        // An identified MorphToMany's current members are its stored pairs; a scoped type's pair
        // whose row the scope hides stays attached.
        if ($this->identifiedMembers !== null && IdentifiedMorphMembers::identifies($relation)) {
            $sent = [];
            foreach ($linkage->resourceIdentifiers as $identifier) {
                $sent[$identifier->type . "\0" . $identifier->id] = true;
            }

            $hidden = [];
            foreach ($this->identifiedMembers->hiddenPairs($server, $parent, $relation, $request) as [$type, $id]) {
                if (!isset($sent[$type . "\0" . $id])) {
                    $hidden[] = new ResourceIdentifier($type, $id);
                }
            }

            return $hidden === [] ? $linkage : new ToManyRelationship([...$linkage->resourceIdentifiers, ...$hidden]);
        }

        if (!$this->relatesToScopingProvider($relation)) {
            return $linkage;
        }

        // Read off a copy, so a relation loaded by the read (an Eloquent model caches it) never
        // stays on the parent the response renders.
        $current = $relation->readValue(clone $parent, $request);
        if (!\is_iterable($current)) {
            return $linkage;
        }

        $candidates = [];
        foreach ($current as $member) {
            if (!\is_object($member)) {
                continue;
            }

            $serializer = $relation->resolveSerializer($member, $server);
            if ($serializer === null) {
                continue;
            }

            $type = $serializer->getType($member);
            if ($this->providers->scopingProviderFor($type) !== null) {
                $id = $serializer->getId($member);
                $candidates[$type][$id] = $id;
            }
        }

        $hidden = [];
        foreach ($candidates as $type => $ids) {
            $type = (string) $type;
            $provider = $this->providers->scopingProviderFor($type);
            \assert($provider !== null);
            $served = $this->served($provider, $type, \array_values(\array_map('strval', $ids)), $server);
            foreach ($ids as $id) {
                if (!isset($served[(string) $id])) {
                    $hidden[] = new ResourceIdentifier($type, (string) $id);
                }
            }
        }

        return $hidden === [] ? $linkage : new ToManyRelationship([...$linkage->resourceIdentifiers, ...$hidden]);
    }

    /**
     * The ids among `$ids`, wire ids of `$type`, whose rows `$provider` serves, as keys: one
     * batched read when the provider offers it, with each id the batch left out asked again
     * through `fetchOne()`, since a batch can leave out a row it cannot key.
     *
     * @param DataProviderInterface<object> $provider
     * @param list<string>                  $ids
     *
     * @return array<string, true>
     */
    private function served(DataProviderInterface $provider, string $type, array $ids, ?Server $server): array
    {
        $found = match (true) {
            $provider instanceof AdmitsIdsInterface => $provider->admitIds($type, $ids),
            $provider instanceof FetchesManyInterface && $server !== null && $server->hasSerializerFor($type) => \array_map(
                static fn(object $row): string => $server->serializerFor($type)->getId($row),
                $provider->fetchMany($type, $ids),
            ),
            default => [],
        };
        $found = \array_fill_keys($found, true);

        $served = [];
        foreach ($ids as $id) {
            if (isset($found[$id]) || $provider->fetchOne($type, $id) !== null) {
                $served[$id] = true;
            }
        }

        return $served;
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
