<?php

declare(strict_types=1);

namespace Workbench\App\WriteTransaction;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\TransactionalDataPersisterInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes a note straight through the `txNotes` persister, and opens and commits a
 * transaction of its own the way an application listener does: on the database connection
 * under Eloquent, on the notes persister in memory.
 */
final readonly class TxSideWriter
{
    public function __construct(private DataPersisterRegistry $persisters) {}

    public function write(string $body): void
    {
        $persister = $this->persisters->forType('txNotes');
        $note = $persister->instantiate('txNotes');
        Accessor::set($note, 'body', $body);

        TxScenario::$levelSeen = $this->eloquent() ? DB::connection()->transactionLevel() : null;
        $persister->create('txNotes', $note);
    }

    public function begin(): void
    {
        $this->eloquent() ? DB::connection()->beginTransaction() : $this->notes()->beginTransaction();
    }

    public function commit(): void
    {
        $this->eloquent() ? DB::connection()->commit() : $this->notes()->commit();
    }

    private function eloquent(): bool
    {
        return $this->persisters->forType('txNotes') instanceof EloquentDataPersister;
    }

    private function notes(): TransactionalDataPersisterInterface
    {
        $persister = $this->persisters->forType('txNotes');
        \assert($persister instanceof TransactionalDataPersisterInterface);

        return $persister;
    }
}
