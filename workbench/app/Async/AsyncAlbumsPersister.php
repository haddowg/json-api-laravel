<?php

declare(strict_types=1);

namespace Workbench\App\Async;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use Workbench\App\Domain\Album;

/**
 * An `albums` persister that accepts every write for **asynchronous processing**
 * instead of committing it: {@see create()} / {@see update()} dispatch the work
 * (here, nothing — a real one would dispatch a Laravel queued job) and return an
 * {@see AcceptedForProcessing} pointing at a pollable {@see Job}. The witness for
 * the package's async-write seam (ADR 0020, the twin of the Symfony bundle's
 * `AsyncArticlesPersister`): the handler renders these as a `202 Accepted` with
 * `Content-Location` + `Retry-After` rather than a `201`/`200`.
 *
 * It registers at the default priority `0`, shadowing the `-128` reference persister
 * for `albums` on both conformance wirings; {@see instantiate()} mints the domain POPO
 * on both because a create target is only ever hydrated, never persisted (the write is
 * deferred to the queue). It is (no-op) transactional so an Atomic Operations batch
 * naming `albums` passes the pre-flight transactionality check and reaches the
 * handler's async guard — the `422` {@see \haddowg\JsonApiLaravel\DataPersister\AsyncWriteNotAllowedInAtomicOperation}
 * this witness exists to prove — rather than the generic `403` a non-transactional
 * persister is refused with. {@see delete()} is a no-op: the seam covers only
 * `create()`/`update()` (delete returns `void`), so an async type's `DELETE` still
 * renders the synchronous `204`.
 */
final class AsyncAlbumsPersister implements DataPersisterInterface, TransactionalDataPersisterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'albums';
    }

    public function instantiate(string $type): object
    {
        return new Album();
    }

    public function create(string $type, object $entity): object
    {
        // A real persister would dispatch the create to a queue here; the witness just
        // accepts it, pointing at the job resource the client polls.
        return AcceptedForProcessing::poll('http://localhost/api/jobs/job-1')
            ->withJob(new Job('job-1', 'queued'), 'jobs')
            ->withRetryAfter(30);
    }

    public function update(string $type, object $entity): object
    {
        return AcceptedForProcessing::poll('http://localhost/api/jobs/job-2')
            ->withJob(new Job('job-2', 'queued'), 'jobs')
            ->withRetryAfter(30);
    }

    public function delete(string $type, object $entity): void {}

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        return $entity;
    }

    public function beginTransaction(): void {}

    public function commit(): void {}

    public function rollback(): void {}
}
