<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Reads a relation declared with a foreign key (`HasMany::make('items', 'inbox-items',
 * 'user')`) through its related type's provider ({@see ReadsByForeignKeyInterface}), for a
 * whole page of parents at once, whatever provider serves them and whatever the parent model
 * holds. Every read path asks it first: the related and relationship endpoints, includes,
 * rendered linkage and first pages, Relationship Queries windows, `?withCount`, a `HasOne`'s
 * target and the members a relationship write starts from.
 */
final class ForeignKeyReads
{
    /**
     * Each relation's parent type, found by identity among the server's resources.
     *
     * @var \WeakMap<RelationInterface, string|false>
     */
    private \WeakMap $parentTypes;

    public function __construct(private readonly DataProviderRegistry $providers)
    {
        $this->parentTypes = new \WeakMap();
    }

    /**
     * Whether `$relation` declares a foreign key and a provider serves its one related type,
     * so every read of it goes through this.
     */
    public function reads(RelationInterface $relation): bool
    {
        $relatedTypes = $relation->relatedTypes();

        return ForeignKey::nameOf($relation) !== null
            && \count($relatedTypes) === 1
            && $this->providers->supportsType($relatedTypes[0]);
    }

    /**
     * Each parent's page of `$relation`, keyed by parent wire id: the rows whose foreign key
     * holds the parent's key, under the related provider's scope, per `$criteria`.
     *
     * @param list<object> $parents
     *
     * @throws \LogicException when the foreign key doesn't resolve or the related type's provider can't read through one; the boot refuses both
     */
    public function batch(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
    {
        [$relatedType, $provider, $foreignKey] = $this->reader($server, $type, $relation);
        $keyed = $this->parents($server, $type, $parents);
        if ($keyed->isEmpty()) {
            return new RelatedBatch([]);
        }

        return $provider->fetchByForeignKey($relatedType, $relation, $foreignKey, $keyed, $criteria, $request);
    }

    /**
     * `$parent`'s page of `$relation`, as its related and relationship endpoints serve it.
     *
     * @return CollectionResult<object>
     */
    public function page(Server $server, string $type, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->batch($server, $type, [$parent], $relation, $criteria, $request)
            ->for($server->serializerFor($type)->getId($parent));
    }

    /**
     * Every row `$relation` reads for `$parent`, unpaged and unfiltered, under the related
     * type's scope: the members a relationship write starts from.
     *
     * @return list<object>
     */
    public function members(Server $server, string $type, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $criteria = new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []), request: $request);
        $items = $this->page($server, $type, $parent, $relation, $criteria, $request)->items;

        return \array_values(\array_filter(\is_array($items) ? $items : \iterator_to_array($items, false), \is_object(...)));
    }

    /**
     * Reads the rows a relation read through a foreign key holds for a row, as core's in-memory
     * filter handler walks a hop, and `null` for any other relation, which the handler reads off
     * the row.
     *
     * @return \Closure(mixed, string, ?RelationInterface): (list<object>|null)
     */
    public function relationReader(Server $server, JsonApiRequestInterface $request): \Closure
    {
        return function (mixed $row, string $walked, ?RelationInterface $relation) use ($server, $request): ?array {
            if ($relation === null || !\is_object($row) || !$this->reads($relation)) {
                return null;
            }
            $parentType = $this->parentTypeOf($server, $relation);

            return $parentType === null ? null : $this->members($server, $parentType, $row, $relation, $request);
        };
    }

    /**
     * Each parent's count of `$relation`'s rows surviving `$criteria`'s filters, keyed by
     * parent wire id, `0` for a parent with none.
     *
     * @param list<object> $parents
     *
     * @return array<string, int>
     */
    public function counts(Server $server, string $type, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): array
    {
        [$relatedType, $provider, $foreignKey] = $this->reader($server, $type, $relation);
        $keyed = $this->parents($server, $type, $parents);
        if ($keyed->isEmpty()) {
            return [];
        }

        $counts = \array_fill_keys($keyed->wireIds(), 0);
        foreach ($provider->countByForeignKey($relatedType, $relation, $foreignKey, $keyed, $criteria, $request) as $wireId => $count) {
            $counts[(string) $wireId] = $count;
        }

        return $counts;
    }

    /**
     * The parents keyed by wire id, each with the key its related rows' foreign key holds. A
     * parent with no id is left out.
     *
     * @param list<object> $parents
     */
    public function parents(Server $server, string $type, array $parents): ForeignKeyParents
    {
        $serializer = $server->serializerFor($type);
        $objects = [];
        $keys = [];
        foreach ($parents as $parent) {
            $wireId = $serializer->getId($parent);
            if ($wireId === '') {
                continue;
            }
            $objects[$wireId] = $parent;
            $keys[$wireId] = ForeignKey::parentKey($serializer, $parent);
        }

        return new ForeignKeyParents($type, $objects, $keys);
    }

    /**
     * The type whose resource declares `$relation`, found by identity, or `null` when no
     * resource of `$server` declares it.
     */
    public function parentTypeOf(Server $server, RelationInterface $relation): ?string
    {
        if (!isset($this->parentTypes[$relation])) {
            $this->parentTypes[$relation] = false;
            foreach ($server->resources()->types() as $type) {
                if (!$server->hasResourceFor($type)) {
                    continue;
                }
                if (\in_array($relation, $server->resourceFor($type)->allFields(), true)) {
                    $this->parentTypes[$relation] = $type;

                    break;
                }
            }
        }

        $type = $this->parentTypes[$relation];

        return $type === false ? null : $type;
    }

    /**
     * The related type, its provider and the resolved foreign key `$relation` reads through.
     *
     * @return array{0: string, 1: ReadsByForeignKeyInterface, 2: ForeignKey}
     *
     * @throws \LogicException when the foreign key doesn't resolve or the provider can't read through one
     */
    private function reader(Server $server, string $type, RelationInterface $relation): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $foreignKey = ForeignKey::of($relation, $server);
        if ($foreignKey === null) {
            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" reads through the foreign key "%s", which is no stored field or to-one relation of the resource serving type "%s".',
                $relation->name(),
                $type,
                (string) ForeignKey::nameOf($relation),
                $relatedType,
            ));
        }

        $provider = $this->providers->forType($relatedType);
        if (!$provider instanceof ReadsByForeignKeyInterface) {
            throw self::unreadable($relation, $type, $foreignKey->name, $provider, $relatedType);
        }

        return [$relatedType, $provider, $foreignKey];
    }

    /**
     * The refusal of a foreign key whose related type's provider can't read through one.
     */
    public static function unreadable(RelationInterface $relation, string $type, string $foreignKey, object $provider, string $relatedType): \LogicException
    {
        return new \LogicException(\sprintf(
            'Relation "%s" on resource type "%s" reads through the foreign key "%s", but the %s serving type "%s" can\'t read through one. Implement %s on it.',
            $relation->name(),
            $type,
            $foreignKey,
            $provider::class,
            $relatedType,
            ReadsByForeignKeyInterface::class,
        ));
    }
}
