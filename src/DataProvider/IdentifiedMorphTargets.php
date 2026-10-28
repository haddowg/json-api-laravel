<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Authorization\GuardedTypes;
use haddowg\JsonApiLaravel\Server\ServerRegistry;

/**
 * Loads the targets of a `MorphTo` declared with `identifiedBy()` from the `[type, id]` pairs
 * its parents store (ADR 0068). Core renders such a relation's linkage straight from the pair,
 * which is right for a target type nothing guards. A guarded type's targets are loaded whenever
 * the linkage renders, so a hidden one renders as none, and every type's targets are loaded when
 * they render as resources: included, or on the related endpoint.
 *
 * Each type's ids load in one read through that type's own provider, the read
 * `GET /{type}/{id}` makes, so a global scope or a scoping provider hides a target exactly as it
 * hides the row there. Each parent then holds its target, or none when the provider didn't return
 * it, until {@see forget()}; the linkage seam hands that answer to core.
 */
final class IdentifiedMorphTargets
{
    private ?Server $server = null;

    private string $serverName = ServerRegistry::DEFAULT_SERVER;

    /**
     * @var \WeakMap<object, array<string, ToOneRelationshipLinkage>>
     */
    private \WeakMap $loaded;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly GuardedTypes $guarded,
    ) {
        $this->loaded = new \WeakMap();
    }

    /**
     * Whether `$relation` reads its target's identifier off the parent.
     *
     * @phpstan-assert-if-true MorphTo $relation
     */
    public static function identifies(RelationInterface $relation): bool
    {
        return $relation instanceof MorphTo && $relation->storesIdentifiers();
    }

    /**
     * Sets the server, named `$serverName`, whose serializers render the loaded targets, and drops
     * every loaded target. A null name is the `default` server.
     */
    public function bind(?Server $server, ?string $serverName = null): void
    {
        $this->server = $server;
        $this->serverName = $serverName ?? ServerRegistry::DEFAULT_SERVER;
        $this->forget();
    }

    /**
     * Drops every loaded target, so a pair a write just changed is read again.
     */
    public function forget(): void
    {
        $this->loaded = new \WeakMap();
    }

    /**
     * The declared types of `$relation` whose rows are guarded on the bound server.
     *
     * @return list<string>
     */
    public function guardedTypes(MorphTo $relation): array
    {
        return \array_values(\array_filter(
            $relation->relatedTypes(),
            fn(string $type): bool => $this->guarded->isGuarded($this->serverName, $type),
        ));
    }

    /**
     * Loads the targets `$relation` identifies on each of `$parents` not loaded yet, one read per
     * type, and returns the targets loaded by type. With `$types`, only pairs of those types load;
     * the others stay unloaded and render as their pair.
     *
     * @param iterable<object>  $parents
     * @param list<string>|null $types
     *
     * @return array<string, list<object>>
     */
    public function load(iterable $parents, MorphTo $relation, JsonApiRequestInterface $request, ?array $types = null): array
    {
        if ($this->server === null || $types === []) {
            return [];
        }

        $name = $relation->name();
        $pending = [];
        foreach ($parents as $parent) {
            if (!isset($this->loaded[$parent][$name])) {
                $pending[] = $parent;
            }
        }

        $rows = [];
        $targets = [];
        foreach ($relation->storedIdentifiers($pending, $request) as $type => $keys) {
            if ($types === null || \in_array($type, $types, true)) {
                $rows[$type] = $this->fetch($this->server, $type, $keys);
                $targets[$type] = \array_values($rows[$type]);
            }
        }

        foreach ($pending as $parent) {
            $pair = $relation->storedIdentifier($parent, $request);
            if ($pair !== null && !isset($rows[$pair[0]])) {
                continue;
            }

            $target = $pair === null ? null : ($rows[$pair[0]][$this->wireId($this->server, $pair[0], $pair[1])] ?? null);

            $entry = $this->loaded[$parent] ?? [];
            $entry[$name] = $target === null ? ToOneRelationshipLinkage::none() : ToOneRelationshipLinkage::to($target);
            $this->loaded[$parent] = $entry;
        }

        return \array_filter($targets, static fn(array $loaded): bool => $loaded !== []);
    }

    /**
     * The target loaded for `$relation` on `$model`, loading it first when its pair names a
     * guarded type, or `null` when its pair renders as stored.
     */
    public function linkageFor(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?ToOneRelationshipLinkage
    {
        if (!\is_object($model) || !self::identifies($relation)) {
            return null;
        }

        $name = $relation->name();
        if (!isset($this->loaded[$model][$name])) {
            $this->load([$model], $relation, $request, $this->guardedTypes($relation));
        }

        return $this->loaded[$model][$name] ?? null;
    }

    /**
     * The rows of `$type` among the stored `$keys` its provider serves, keyed by wire id.
     *
     * @param non-empty-list<string|int> $keys
     *
     * @return array<string, object>
     */
    private function fetch(Server $server, string $type, array $keys): array
    {
        if (!$this->providers->supportsType($type) || !$server->hasSerializerFor($type)) {
            return [];
        }

        $provider = $this->providers->forType($type);
        $ids = \array_map(fn(string|int $key): string => $this->wireId($server, $type, $key), $keys);

        if ($provider instanceof FetchesManyInterface) {
            $found = $provider->fetchMany($type, $ids);
        } else {
            $found = [];
            foreach ($ids as $id) {
                $row = $provider->fetchOne($type, $id);
                if ($row !== null) {
                    $found[] = $row;
                }
            }
        }

        $serializer = $server->serializerFor($type);
        $byId = [];
        foreach ($found as $row) {
            $byId[$serializer->getId($row)] = $row;
        }

        return $byId;
    }

    /**
     * A stored key as the type's serializer renders its id, through the id encoder it declares.
     */
    private function wireId(Server $server, string $type, string|int $key): string
    {
        $serializer = $server->hasSerializerFor($type) ? $server->serializerFor($type) : null;
        if ($serializer instanceof AbstractResource) {
            foreach ($serializer->allFields() as $field) {
                if ($field instanceof Id) {
                    return $field->encoder()?->encode($key) ?? (string) $key;
                }
            }
        }

        return (string) $key;
    }
}
