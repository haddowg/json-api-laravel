<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataPersister\AbstractDataPersister;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;

/**
 * Writes grants into {@see FkGrantProvider}. A relationship write records the ids it sent
 * without reading what the grant holds. A transaction restores the grants it began with on
 * rollback.
 */
final class FkGrantPersister extends AbstractDataPersister implements TransactionalDataPersisterInterface
{
    /**
     * @var list<array<string, array<string, FkGrant>>>
     */
    private array $snapshots = [];

    public function __construct(private readonly FkGrantProvider $provider) {}

    public function beginTransaction(): void
    {
        $copy = [];
        foreach ($this->provider->grants as $type => $grants) {
            $copy[$type] = \array_map(static fn(FkGrant $grant): FkGrant => clone $grant, $grants);
        }
        $this->snapshots[] = $copy;
    }

    public function commit(): void
    {
        \array_pop($this->snapshots);
    }

    public function rollback(): void
    {
        $snapshot = \array_pop($this->snapshots);
        if ($snapshot !== null) {
            $this->provider->grants = $snapshot;
        }
    }

    public function supports(string $type): bool
    {
        return $this->provider->supports($type);
    }

    public function instantiate(string $type): object
    {
        return new FkGrant();
    }

    public function create(string $type, object $entity): object
    {
        \assert($entity instanceof FkGrant);
        if ($entity->id === '') {
            $entity->id = 'g' . (\count($this->provider->grants[$type]) + 1);
        }

        return $this->provider->grants[$type][$entity->id] = $entity;
    }

    public function update(string $type, object $entity): object
    {
        return $entity;
    }

    public function delete(string $type, object $entity): void {}

    public function mutateRelationship(string $type, object $entity, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, bool $flush = true): object
    {
        \assert($entity instanceof FkGrant && $linkage instanceof ToManyRelationship && $mode === Mode::Replace);
        $ids = \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', $linkage->getResourceIdentifierIds());
        if ($relation->name() === 'lockedSecrets') {
            $entity->lockedSecrets = $ids;
        } else {
            $entity->received = $ids;
        }

        return $entity;
    }
}
