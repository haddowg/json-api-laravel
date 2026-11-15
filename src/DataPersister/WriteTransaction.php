<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

/**
 * Runs one write in a transaction on the persister that owns it: the CRUD handler wraps
 * every create, update, delete and relationship mutation in it, and the action invoker
 * every action called with POST, PATCH, PUT or DELETE. A GET action runs through it with no
 * persister, so it opens no transaction.
 *
 * The transaction opens before the write's first step, so a flush a before hook makes
 * joins it, and anything that throws before the commit rolls all of it back. A
 * persister that can't transact runs the write as it is, and inside an Atomic
 * Operations batch the batch owns the transaction, so the write runs in it.
 *
 * Each write also opens an {@see AfterCommit} scope, with or without a transaction: the
 * callbacks registered during the write run once it commits, or once it returns when no
 * transaction opened, and are dropped when it throws. They wait for an application
 * transaction still open on the persister's connection.
 */
final class WriteTransaction
{
    /**
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function run(?DataPersisterInterface $persister, ?WriteTransactionContext $context, \Closure $write, ?AfterCommit $afterCommit = null): mixed
    {
        $transaction = $persister instanceof TransactionalDataPersisterInterface && !($context?->isActive() ?? false) ? $persister : null;

        $connection = $persister instanceof UsesDatabaseConnection ? $persister->transactionConnection() : null;
        $afterCommit?->open($connection !== null ? [$connection] : []);
        try {
            $transaction?->beginTransaction();
            try {
                $result = $write();
                $transaction?->commit();
            } catch (\Throwable $throwable) {
                $transaction?->rollback();

                throw $throwable;
            }
        } catch (\Throwable $throwable) {
            $afterCommit?->discard();

            throw $throwable;
        }

        $afterCommit?->commit();

        return $result;
    }
}
