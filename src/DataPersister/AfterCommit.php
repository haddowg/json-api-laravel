<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

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
     * The open scopes, innermost last, each holding the callbacks registered inside it.
     *
     * @var list<list<callable(): mixed>>
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
        if ($this->scopes === []) {
            $this->call($callback);

            return;
        }

        $this->scopes[\array_key_last($this->scopes)][] = $callback;
    }

    /**
     * Opens a scope for a write.
     *
     * @internal
     */
    public function open(): void
    {
        $this->scopes[] = [];
    }

    /**
     * Closes the innermost scope after its write committed: its callbacks move to the scope
     * around it, or run when it was the outermost.
     *
     * @internal
     */
    public function commit(): void
    {
        $callbacks = \array_pop($this->scopes) ?? [];

        if ($this->scopes !== []) {
            $outer = \array_key_last($this->scopes);
            $this->scopes[$outer] = [...$this->scopes[$outer], ...$callbacks];

            return;
        }

        foreach ($callbacks as $callback) {
            $this->call($callback);
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
