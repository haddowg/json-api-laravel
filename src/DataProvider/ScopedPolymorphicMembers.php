<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Server\Server;

/**
 * Narrows a polymorphic relation's members to the rows each member type's own scoping provider
 * admits (ADR 0013). The members a relation holds are the candidates; they are grouped by member
 * type, and for each type served by its own scoping provider the candidate ids of every parent
 * are looked up in one read through that provider. Members of other types are kept as loaded,
 * since their reference provider's rows are exactly what a relation read yields.
 */
final class ScopedPolymorphicMembers
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    /**
     * Each parent's members, narrowed and in their original order, keyed as `$candidates` is.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, iterable<mixed>> $candidates the members each parent holds
     *
     * @return array<TKey, list<object>>
     */
    public function narrow(Server $server, string $parentType, RelationInterface $relation, array $candidates): array
    {
        $members = [];
        $idsByType = [];
        foreach ($candidates as $key => $rows) {
            $members[$key] = [];
            foreach ($rows as $row) {
                if (!\is_object($row)) {
                    continue;
                }
                $serializer = $relation->resolveSerializer($row, $server);
                $type = $serializer?->getType($row);
                $id = $serializer?->getId($row);
                $members[$key][] = [$row, $type, $id];
                if ($type !== null && $id !== null) {
                    $idsByType[$type][$id] = true;
                }
            }
        }

        $admitted = [];
        foreach ($idsByType as $type => $ids) {
            $provider = $this->providers->scopedRelatedProvider($parentType, $type);
            if ($provider !== null) {
                $admitted[$type] = $this->admittedIds($server, $provider, $type, \array_map('strval', \array_keys($ids)));
            }
        }

        $narrowed = [];
        foreach ($members as $key => $rows) {
            $narrowed[$key] = [];
            foreach ($rows as [$row, $type, $id]) {
                if ($type === null || $id === null || !isset($admitted[$type]) || isset($admitted[$type][$id])) {
                    $narrowed[$key][] = $row;
                }
            }
        }

        return $narrowed;
    }

    /**
     * {@see narrow()} for one relation's rows, as the closure {@see FilterPaths::of()} takes:
     * the rows a filter's in-memory walk reaches through a relation declared on a type, kept
     * to those the related type's scoping provider admits.
     *
     * @return \Closure(RelationInterface, string, list<object>): list<object>
     */
    public function admission(Server $server): \Closure
    {
        return fn(RelationInterface $relation, string $parentType, array $rows): array => $this->narrow($server, $parentType, $relation, [$rows])[0];
    }

    /**
     * The closure {@see FilterPaths::of()} takes to narrow a `WhereMorph`'s targets: the
     * storage keys of a type, reached through a polymorphic relation declared on a type, kept to
     * those the type's scoping provider admits. A type no scoping provider serves keeps them all.
     * The stored keys were never read through any provider, so the type's provider decides even
     * when it also serves the type the relation is declared on.
     *
     * @return \Closure(RelationInterface, string, string, list<mixed>): list<mixed>
     */
    public function morphAdmission(Server $server): \Closure
    {
        return fn(RelationInterface $relation, string $parentType, string $type, array $keys): array => $this->admittedKeys($server, $type, $keys);
    }

    /**
     * The keys among `$keys`, storage keys of `$type`, that the type's scoping provider
     * admits; all of them when no scoping provider serves it.
     *
     * @param array<mixed> $keys
     *
     * @return list<mixed>
     */
    private function admittedKeys(Server $server, string $type, array $keys): array
    {
        $keys = \array_values($keys);
        $provider = $this->providers->scopingProviderFor($type);
        if ($provider === null || $keys === []) {
            return $keys;
        }

        // A provider reads wire ids, so each stored key is asked about as its type renders it.
        $wire = [];
        foreach ($keys as $key) {
            if (\is_string($key) || \is_int($key)) {
                $wire[(string) $key] = self::wireId($server, $type, $key);
            }
        }
        $admitted = $this->admittedIds($server, $provider, $type, \array_values(\array_unique($wire)));

        return \array_values(\array_filter($keys, static fn(mixed $key): bool => (\is_string($key) || \is_int($key)) && isset($admitted[$wire[(string) $key]])));
    }

    /**
     * A stored key as the type's serializer renders its id, through the id encoder it declares.
     */
    private static function wireId(Server $server, string $type, string|int $key): string
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

    /**
     * Applies `$criteria` to members already narrowed, so a page of them stays full and its total
     * counts the narrowed set: the related endpoint's read of a polymorphic relation that reaches
     * a scoped type.
     *
     * @param list<object> $members
     *
     * @return CollectionResult<object>
     */
    public function window(CollectionCriteria $criteria, array $members): CollectionResult
    {
        /** @var list<object> $filtered */
        $filtered = (new CriteriaApplier())->apply($criteria, $members, new ScopedArrayFilterHandler(new ArrayFilterHandler()), new ScopedArraySortHandler(new ArraySortHandler()));

        return (new WindowExecutor())->run(
            $criteria->window,
            countable: $criteria->wantsCount,
            all: static fn(): array => $filtered,
            count: static fn(): int => \count($filtered),
            page: static fn(int $offset, int $limit): array => \array_slice($filtered, $offset, $limit),
            probe: static fn(int $offset, int $limit): array => \array_slice($filtered, $offset, $limit),
        );
    }

    /**
     * The ids among `$ids` that `$provider` serves, as a set.
     *
     * @param DataProviderInterface<object> $provider
     * @param list<string>                  $ids
     *
     * @return array<string, true>
     */
    private function admittedIds(Server $server, DataProviderInterface $provider, string $type, array $ids): array
    {
        $admitted = [];
        if ($provider instanceof FetchesManyInterface) {
            $serializer = $server->serializerFor($type);
            foreach ($provider->fetchMany($type, $ids) as $row) {
                $admitted[$serializer->getId($row)] = true;
            }

            return $admitted;
        }

        foreach ($ids as $id) {
            if ($provider->fetchOne($type, $id) !== null) {
                $admitted[$id] = true;
            }
        }

        return $admitted;
    }
}
