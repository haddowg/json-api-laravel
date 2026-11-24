<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\WritesForeignKeysInterface;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\FetchesManyInterface;
use haddowg\JsonApiLaravel\DataProvider\ForeignKeyReads;
use haddowg\JsonApiLaravel\DataProvider\StoredIds;

/**
 * Writes a relation declared with a foreign key on its related rows, never the parent: the
 * related type's persister ({@see WritesForeignKeysInterface}) points the rows joining the
 * parent at it and clears the key on the rows leaving it. The members a write starts from are
 * the ones the relation's reads serve ({@see ForeignKeyReads}), so a row the related type's
 * scope hides is never touched, and the linked rows load through the related type's provider,
 * so one it doesn't serve is a `404`.
 *
 * @internal
 */
final class ForeignKeyWrites
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly ForeignKeyReads $reads,
    ) {}

    /**
     * Whether a write of `$relation` goes through its foreign key.
     */
    public function writes(RelationInterface $relation): bool
    {
        return $this->reads->reads($relation);
    }

    /**
     * The rows a write of `$linkage` moves through `$relation`'s foreign key: the linked rows
     * not yet members join the parent; on a replace the members the linkage leaves out, and on
     * a remove the linked members, leave it. A leaving row whose foreign key storage requires
     * refuses the write with the `403` of the mutation that drops it.
     *
     * @return array{writer: WritesForeignKeysInterface, foreignKey: ForeignKey, parentType: string, parentKey: mixed, attach: list<object>, detach: list<object>}
     *
     * @throws ResourceNotFound                            when a linked row isn't served by the related type's provider
     * @throws RemovalProhibited|FullReplacementProhibited when a leaving row's foreign key can't be cleared
     */
    public function plan(Server $server, string $type, object $parent, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, JsonApiRequestInterface $request): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $foreignKey = ForeignKey::of($relation, $server)
            ?? throw new \LogicException(\sprintf('Relation "%s" on resource type "%s" writes through the foreign key "%s", which is no stored field or to-one relation of type "%s".', $relation->name(), $type, (string) ForeignKey::nameOf($relation), $relatedType));
        $writer = $this->writer($type, $relation, $foreignKey->name);

        $relatedSerializer = $server->serializerFor($relatedType);
        $current = [];
        foreach ($this->reads->members($server, $type, $parent, $relation, $request) as $row) {
            $current[$relatedSerializer->getId($row)] = $row;
        }

        $ids = [];
        $identifiers = $linkage instanceof ToManyRelationship ? $linkage->resourceIdentifiers : \array_filter([$linkage->resourceIdentifier]);
        foreach ($identifiers as $identifier) {
            if ($identifier->id !== null && $identifier->id !== '') {
                $ids[(string) $identifier->id] = (string) $identifier->id;
            }
        }

        $attach = [];
        if ($mode !== Mode::Remove) {
            $attach = $this->rowsById($server, $relatedType, \array_values(\array_diff_key($ids, $current)));
        }

        $detach = match ($mode) {
            Mode::Remove => \array_values(\array_intersect_key($current, $ids)),
            Mode::Replace => \array_values(\array_diff_key($current, $ids)),
            default => [],
        };

        if ($detach !== [] && $writer->requiresForeignKey($relatedType, $foreignKey)) {
            throw $mode === Mode::Remove || ($linkage instanceof ToOneRelationship && $linkage->isEmpty())
                ? new RemovalProhibited($relation->name())
                : new FullReplacementProhibited($relation->name());
        }

        return [
            'writer' => $writer,
            'foreignKey' => $foreignKey,
            'parentType' => $type,
            'parentKey' => ForeignKey::parentKey($server->serializerFor($type), $parent),
            'attach' => $attach,
            'detach' => $detach,
        ];
    }

    /**
     * Applies `$plan` to the related rows. A foreign key naming a `MorphTo` identified by
     * columns also has its type field set to the value naming the parent's type on each joining
     * row, and cleared on each leaving one, before the writer sets the id field.
     *
     * @param array{writer: WritesForeignKeysInterface, foreignKey: ForeignKey, parentType: string, parentKey: mixed, attach: list<object>, detach: list<object>} $plan
     */
    public function apply(RelationInterface $relation, object $parent, array $plan): void
    {
        $morph = $plan['foreignKey']->morphColumns();
        if ($morph !== null) {
            $stored = $morph->storedValueFor($plan['parentType']);
            foreach ($plan['attach'] as $row) {
                Accessor::set($row, $morph->type, $stored);
            }
            foreach ($plan['detach'] as $row) {
                Accessor::set($row, $morph->type, null);
            }
        }

        $plan['writer']->writeForeignKey($relation->relatedTypes()[0] ?? '', $plan['foreignKey'], $parent, $plan['parentKey'], $plan['attach'], $plan['detach']);
    }

    /**
     * Each row `$plan` attaches, with the parent of `$type` it leaves, or none: a to-one
     * foreign key's target, or the row of `$type` a field's key names.
     *
     * @param array{writer: WritesForeignKeysInterface, foreignKey: ForeignKey, parentType: string, parentKey: mixed, attach: list<object>, detach: list<object>} $plan
     *
     * @return list<array{memberType: string, member: object, ownerType: ?string, owner: ?object}>
     */
    public function moves(Server $server, string $type, RelationInterface $relation, array $plan): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $morph = $plan['foreignKey']->morphColumns();
        $moves = [];
        foreach ($plan['attach'] as $row) {
            // A polymorphic foreign key names its owner's type and id, either form of the id.
            if ($morph !== null) {
                try {
                    $pair = $morph->pair($row);
                } catch (\LogicException) {
                    $pair = null;
                }
                $owner = $pair !== null && $this->providers->supportsType($pair[0])
                    ? $this->providers->forType($pair[0])->fetchOne($pair[0], StoredIds::wireId($server, $pair[0], $pair[1]))
                    : null;
                $moves[] = ['memberType' => $relatedType, 'member' => $row, 'ownerType' => $owner !== null ? $pair[0] ?? null : null, 'owner' => $owner];

                continue;
            }

            $from = Accessor::get($row, $plan['foreignKey']->column);
            if (!\is_object($from) && $from !== null) {
                $from = $this->parentByKey($server, $type, $from);
            }

            $moves[] = ['memberType' => $relatedType, 'member' => $row, 'ownerType' => \is_object($from) ? $type : null, 'owner' => \is_object($from) ? $from : null];
        }

        return $moves;
    }

    /**
     * `$relation`'s members through its foreign key, as `[type, wire id]` pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function memberPairs(Server $server, string $type, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $serializer = $server->serializerFor($relatedType);

        return \array_map(static fn(object $row): array => [$relatedType, $serializer->getId($row)], $this->reads->members($server, $type, $parent, $relation, $request));
    }

    /**
     * The persisters of the related types `$relations` write through their foreign keys,
     * other than `$persister`, so their writes commit or roll back with the parent's.
     *
     * @param iterable<RelationInterface> $relations
     *
     * @return list<DataPersisterInterface>
     */
    public function relatedPersisters(DataPersisterInterface $persister, iterable $relations): array
    {
        $related = [];
        foreach ($relations as $relation) {
            $relatedType = $relation->relatedTypes()[0] ?? null;
            if ($relatedType === null || !$this->writes($relation) || !$this->persisters->supportsType($relatedType)) {
                continue;
            }

            $writer = $this->persisters->forType($relatedType);
            if ($writer !== $persister) {
                $related[\spl_object_id($writer)] = $writer;
            }
        }

        return \array_values($related);
    }

    /**
     * The persister writing `$relation`'s foreign key on its related type.
     *
     * @throws \LogicException when no persister of the related type writes one
     */
    private function writer(string $type, RelationInterface $relation, string $foreignKey): WritesForeignKeysInterface
    {
        $relatedType = $relation->relatedTypes()[0] ?? '';
        $writer = $this->persisters->supportsType($relatedType) ? $this->persisters->forType($relatedType) : null;
        if (!$writer instanceof WritesForeignKeysInterface) {
            throw self::unwritable($relation, $type, $foreignKey, $writer, $relatedType);
        }

        return $writer;
    }

    /**
     * The refusal of a foreign key no persister of its related type writes.
     */
    public static function unwritable(RelationInterface $relation, string $type, string $foreignKey, ?object $persister, string $relatedType): \LogicException
    {
        return new \LogicException(\sprintf(
            'Relation "%s" on resource type "%s" writes through the foreign key "%s", but %s serving type "%s" writes one. Implement %s on it.',
            $relation->name(),
            $type,
            $foreignKey,
            $persister === null ? 'no persister' : 'the ' . $persister::class,
            $relatedType,
            WritesForeignKeysInterface::class,
        ));
    }

    /**
     * The rows of `$type` with `$ids`, as its provider serves them.
     *
     * @param list<string> $ids
     *
     * @return list<object>
     *
     * @throws ResourceNotFound when the provider serves no row for one of them
     */
    private function rowsById(Server $server, string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $provider = $this->providers->forType($type);
        $found = [];
        if ($provider instanceof FetchesManyInterface) {
            $serializer = $server->serializerFor($type);
            foreach ($provider->fetchMany($type, $ids) as $row) {
                $found[$serializer->getId($row)] = $row;
            }
        }

        $rows = [];
        foreach ($ids as $id) {
            $row = $found[$id] ?? $provider->fetchOne($type, $id);
            if ($row === null) {
                throw new ResourceNotFound();
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The parent of `$type` stored under `$key`, the storage key or the wire id.
     */
    private function parentByKey(Server $server, string $type, mixed $key): ?object
    {
        if ((!\is_int($key) && !\is_string($key) && !$key instanceof \Stringable) || !$this->providers->supportsType($type)) {
            return null;
        }

        return $this->providers->forType($type)->fetchOne($type, StoredIds::wireId($server, $type, $key));
    }
}
