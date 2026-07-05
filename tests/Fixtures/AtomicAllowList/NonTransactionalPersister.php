<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\AtomicAllowList;

use haddowg\JsonApiLaravel\DataPersister\AbstractDataPersister;

/**
 * A persister that supports a type but does NOT implement
 * {@see \haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface} — so an
 * Atomic Operations batch touching its type is refused in pre-flight
 * ({@see \haddowg\JsonApiLaravel\Atomic\AtomicOperationsNotSupported}). Its write methods are
 * never reached (the batch is refused before any write), so they are minimal.
 */
final class NonTransactionalPersister extends AbstractDataPersister
{
    public function __construct(private readonly string $type) {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function instantiate(string $type): object
    {
        return new Entry();
    }

    public function create(string $type, object $entity): object
    {
        return $entity;
    }

    public function update(string $type, object $entity): object
    {
        return $entity;
    }

    public function delete(string $type, object $entity): void {}
}
