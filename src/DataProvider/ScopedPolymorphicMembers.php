<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Server\Server;

/**
 * Narrows a polymorphic relation's members to the rows each member type's own scoping provider
 * admits (ADR 0051). The members a relation holds are the candidates; they are grouped by member
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
        $filtered = (new CriteriaApplier())->apply($criteria, $members, new ArrayFilterHandler(), new ArraySortHandler());

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
