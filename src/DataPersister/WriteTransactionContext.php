<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

/**
 * The per-request flag + post-commit-hook queue the Atomic Operations executor drives so
 * the lifecycle hooks of a batched write run AFTER the batch commits, not inline with each
 * operation — the Laravel twin of the bundle's `DataPersister\WriteTransactionContext`, and
 * the deferral seam PLAN decision 12 prescribes.
 *
 * On the **single-operation** write path this context stays {@see isActive() inactive}: the
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler} fires each After* lifecycle
 * event immediately (once the events agent wires them). It is only the Atomic Operations
 * executor that {@see activate()}s the context for the duration of a batch, opens the
 * transaction on the persisters, lets each operation {@see enqueuePostCommit() enqueue} its
 * After* dispatch instead of firing it, and — after the transaction commits —
 * {@see drain()}s the queue (or, on a rollback, discards it via {@see deactivate()}).
 *
 * **Post-commit hooks run after the batch is durably committed, best-effort.** By the time
 * the queue {@see drain()}s, the batch's writes are already durable — so a hook that throws
 * does NOT fail the response and rolls nothing back. The executor drains with an error
 * handler that logs each such exception and lets the remaining hooks run.
 *
 * It is a stateful **singleton** — its only consumer, the singleton
 * {@see \haddowg\JsonApiLaravel\Operation\CrudOperationHandler}, captures it at construction,
 * so a `scoped()` binding would mint per-request instances the handler never uses; a singleton
 * keeps one consistent instance everywhere. Cross-batch cleanliness rests on the executor's
 * always-run {@see deactivate()} (called in a `finally` on both the commit and rollback
 * paths), so no batch ever leaves the context active or partly queued; {@see reset()} clears
 * it for an Octane/queue worker reset hook. (PHP serves one request at a time per worker, so
 * the shared singleton is never touched concurrently.)
 */
final class WriteTransactionContext
{
    private bool $active = false;

    /**
     * @var list<callable(): void>
     */
    private array $postCommit = [];

    /**
     * Whether a batch is in flight: `true` only while the executor has {@see activate()}d
     * the context. On the single-op path it is always `false`, so the handler fires its
     * After* hooks inline.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Marks a batch as in flight (the executor wraps the batch in
     * {@see activate()}/{@see deactivate()}). While active the handler enqueues its After*
     * dispatch instead of firing it.
     */
    public function activate(): void
    {
        $this->active = true;
    }

    /**
     * Ends the in-flight batch and clears any not-yet-drained queue — the executor's
     * clean-up after committing-and-draining OR after a rollback (where the queue is
     * discarded undrained, so a rolled-back batch fires no After* hooks). Safe to call
     * unconditionally.
     */
    public function deactivate(): void
    {
        $this->active = false;
        $this->postCommit = [];
    }

    /**
     * Enqueues a callable to run after the batch's transaction commits. The handler
     * enqueues its After* dispatch here while the context is active; the executor
     * {@see drain()}s the queue post-commit.
     *
     * @param callable(): void $callback
     */
    public function enqueuePostCommit(callable $callback): void
    {
        $this->postCommit[] = $callback;
    }

    /**
     * Runs every enqueued post-commit callable in FIFO order, then clears the queue — the
     * executor calls this once the batch's transaction has committed, so the deferred
     * After* hooks observe the durably-persisted state. On a rollback the executor never
     * drains (it {@see deactivate()}s instead), so the hooks of a rolled-back batch never
     * run.
     *
     * The drain is **best-effort**: the batch is already durably committed by the time it
     * runs, so a throwing post-commit hook must NOT turn a successful batch into a failure.
     * When an `$onError` handler is given, each hook's exception is passed to it and the
     * remaining hooks still run; with no handler the first exception propagates.
     *
     * @param ?callable(\Throwable): void $onError invoked with each hook's exception (the
     *                                             remaining hooks then run); null lets the
     *                                             first exception propagate
     */
    public function drain(?callable $onError = null): void
    {
        $callbacks = $this->postCommit;
        $this->postCommit = [];

        foreach ($callbacks as $callback) {
            if ($onError === null) {
                $callback();

                continue;
            }

            try {
                $callback();
            } catch (\Throwable $throwable) {
                $onError($throwable);
            }
        }
    }

    /**
     * Clears the active flag and the deferred queue between requests in a long-lived
     * container (an Octane worker / queue), so no request inherits a previous one's
     * in-flight batch state.
     */
    public function reset(): void
    {
        $this->active = false;
        $this->postCommit = [];
    }
}
