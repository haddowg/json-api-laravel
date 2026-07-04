<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataPersister\Eloquent;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Album;

/**
 * The reference Eloquent persister executed against real SQLite: the create/update/delete
 * commits, the store-generated id materialisation, and — the point of the suite — the
 * transaction boundary. A write is wrapped in a `transaction()` on the model's connection,
 * so it composes as a savepoint under an outer transaction and rolls back with it; the
 * segregated {@see \haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface}
 * opens/commits/(guarded-)rolls-back one connection transaction for an atomic batch.
 *
 * @internal
 */
#[CoversClass(EloquentDataPersister::class)]
final class EloquentDataPersisterTest extends EloquentTestCase
{
    #[Test]
    public function createPersistsTheEntityAndPopulatesTheAutoIncrementId(): void
    {
        $persister = $this->persister();

        $entity = $persister->create('albums', $this->albumEntity());

        \assert($entity instanceof Album);
        self::assertSame(1, $entity->id);
        self::assertSame(1, Album::query()->count());
    }

    #[Test]
    public function updateCommitsTheHydratedChangeInPlace(): void
    {
        $this->seedFixtures();
        $persister = $this->persister();

        $album = Album::query()->findOrFail(1);
        $album->title = 'Edited In Place';
        $persister->update('albums', $album);

        self::assertSame('Edited In Place', Album::query()->findOrFail(1)->title);
    }

    #[Test]
    public function deleteRemovesTheEntity(): void
    {
        $this->seedFixtures();
        $persister = $this->persister();

        $persister->delete('albums', Album::query()->findOrFail(1));

        self::assertNull(Album::query()->find(1));
        self::assertSame(1, Album::query()->count());
    }

    #[Test]
    public function aCreateRolledBackByAnOuterTransactionLeavesNoRow(): void
    {
        $persister = $this->persister();

        // The per-op write nests as a savepoint inside the outer transaction, so when the
        // outer transaction rolls back on the thrown exception the created row goes with
        // it — nothing is durable.
        $propagated = false;
        try {
            DB::transaction(function () use ($persister): void {
                $persister->create('albums', $this->albumEntity());

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $exception) {
            $propagated = $exception->getMessage() === 'boom';
        }

        self::assertTrue($propagated, 'The outer transaction should have propagated the exception.');
        self::assertSame(0, Album::query()->count());
    }

    #[Test]
    public function beginThenCreateMaterialisesTheIdThenRollbackDiscardsIt(): void
    {
        $persister = $this->persister();

        $persister->beginTransaction();
        $entity = $persister->create('albums', $this->albumEntity());

        // The id is materialised immediately (inside the open batch transaction) so a
        // later batch operation could reference it — yet the row is not durable.
        \assert($entity instanceof Album);
        self::assertSame(1, $entity->id);
        self::assertSame(1, Album::query()->count());

        $persister->rollback();

        self::assertSame(0, Album::query()->count());
    }

    #[Test]
    public function beginThenCreateThenCommitIsDurable(): void
    {
        $persister = $this->persister();

        $persister->beginTransaction();
        $persister->create('albums', $this->albumEntity());
        $persister->commit();

        self::assertSame(1, Album::query()->count());
    }

    #[Test]
    public function rollbackIsAGuardedNoOpWhenNoTransactionIsOpen(): void
    {
        $persister = $this->persister();

        // No begin: the guarded rollback must not raise a "no active transaction" error.
        $persister->rollback();

        self::assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function instantiateReturnsAFreshUnsavedModelOfTheType(): void
    {
        $persister = $this->persister();

        $entity = $persister->instantiate('albums');

        self::assertInstanceOf(Album::class, $entity);
        self::assertFalse($entity->exists);
    }

    private function persister(): EloquentDataPersister
    {
        return new EloquentDataPersister(['albums' => Album::class]);
    }

    private function albumEntity(): Album
    {
        return new Album([
            'title' => 'A New Album',
            'status' => 'released',
            'released_at' => '2020-02-02 00:00:00',
        ]);
    }
}
