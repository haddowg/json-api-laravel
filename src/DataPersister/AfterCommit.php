<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs code once the current write has committed. An action handler, a Before* listener or
 * a persister injects it and calls {@see register()}; an action handler can also call
 * {@see \haddowg\JsonApiLaravel\Action\ActionContext::afterCommit()}.
 *
 * Every write the package runs through {@see WriteTransaction} opens a scope here, whether or
 * not a transaction opens with it. A callback registered inside a scope runs when the outermost
 * scope commits: after the persister's commit, in the order it was registered, before the After*
 * events fire and the response renders. A scope that rolls back drops its callbacks, and a
 * scope inside another hands its callbacks to the outer one. An Atomic Operations batch is
 * one scope, so callbacks its operations register wait for the batch to commit. A write
 * whose persister can't transact still opens a scope, so its callbacks run once the write
 * returns. With no scope open, {@see register()} runs the callback at once.
 *
 * A write can itself run inside a transaction the application opened, such as a test or a
 * command calling the package from inside `DB::transaction()`. When the outermost scope
 * commits while a connection its persister {@see UsesDatabaseConnection uses} is still in a
 * transaction, the callbacks go to that connection's `afterCommit()` instead: they run when
 * the application commits and are dropped when it rolls back.
 *
 * The write has committed by the time a callback runs, so a callback that throws is logged at
 * error level and the rest still run; the response goes out as it would have.
 *
 * It is a singleton like {@see WriteTransactionContext}, since the singleton handler, invoker
 * and persisters capture it at construction. Every write closes its scope whether it commits
 * or throws, and the handler calls {@see reset()} at the start of each outermost dispatch, so
 * a long-lived worker never carries a scope into its next request.
 */
final class AfterCommit
{
    /**
     * The open scopes, innermost last: the callbacks registered inside each and the database
     * connections its writes ran on.
     *
     * @var list<array{callbacks: list<callable(): mixed>, connections: list<ConnectionInterface>}>
     */
    private array $scopes = [];

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs `$callback` once the current write commits, or at once outside any write.
     *
     * @param callable(): mixed $callback
     */
    public function register(callable $callback): void
    {
        $last = \array_key_last($this->scopes);
        if ($last === null) {
            $this->call($callback);

            return;
        }

        $this->scopes[$last]['callbacks'][] = $callback;
    }

    /**
     * Opens a scope for a write on the given database connections.
     *
     * @param list<ConnectionInterface> $connections
     *
     * @internal
     */
    public function open(array $connections = []): void
    {
        $this->scopes[] = ['callbacks' => [], 'connections' => $connections];
    }

    /**
     * Closes the innermost scope after its write committed. Its callbacks move to the scope
     * around it. When it was the outermost they run, or wait for the application's own
     * transaction when one is still open on a connection the write used.
     *
     * @internal
     */
    public function commit(): void
    {
        $scope = \array_pop($this->scopes);
        if ($scope === null) {
            return;
        }

        $outer = \array_key_last($this->scopes);
        if ($outer !== null) {
            $this->scopes[$outer]['callbacks'] = [...$this->scopes[$outer]['callbacks'], ...$scope['callbacks']];
            $this->scopes[$outer]['connections'] = [...$this->scopes[$outer]['connections'], ...$scope['connections']];

            return;
        }

        if ($scope['callbacks'] !== []) {
            $this->deliver($scope['callbacks'], $scope['connections']);
        }
    }

    /**
     * Closes the innermost scope after its write rolled back, dropping its callbacks.
     *
     * @internal
     */
    public function discard(): void
    {
        \array_pop($this->scopes);
    }

    /**
     * Drops every open scope and its callbacks.
     *
     * @internal
     */
    public function reset(): void
    {
        $this->scopes = [];
    }

    /**
     * Runs the callbacks, or hands them to the first connection still inside a transaction
     * the application opened, checking the rest again once that one commits.
     *
     * @param list<callable(): mixed>   $callbacks
     * @param list<ConnectionInterface> $connections
     */
    private function deliver(array $callbacks, array $connections): void
    {
        while ($connections !== []) {
            $connection = \array_shift($connections);
            if ($connection instanceof Connection && $connection->transactionLevel() > 0) {
                $connection->afterCommit(function () use ($callbacks, $connections): void {
                    $this->deliver($callbacks, $connections);
                });

                return;
            }
        }

        foreach ($callbacks as $callback) {
            $this->call($callback);
        }
    }

    /**
     * @param callable(): mixed $callback
     */
    private function call(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'A callback registered to run after a JSON:API write committed threw; the write already committed, so the error is logged and the remaining callbacks still run.',
                ['exception' => $throwable],
            );
        }
    }
}
