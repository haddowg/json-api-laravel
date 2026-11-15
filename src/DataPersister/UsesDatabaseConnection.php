<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

use Illuminate\Database\ConnectionInterface;

/**
 * The capability a {@see DataPersisterInterface} implements when its writes run on a
 * database connection, as the {@see Eloquent\EloquentDataPersister} does. When a write
 * commits while the application still holds a transaction of its own on that connection,
 * {@see AfterCommit} hands the write's callbacks to the connection's `afterCommit()`, so
 * they wait for the application's commit and are dropped by its rollback.
 */
interface UsesDatabaseConnection
{
    /**
     * The connection the persister's writes and transaction run on, or null when it has none,
     * as a decorator over a persister without one may.
     */
    public function transactionConnection(): ?ConnectionInterface;
}
