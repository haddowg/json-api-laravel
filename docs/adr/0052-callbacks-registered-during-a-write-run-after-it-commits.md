# Callbacks registered during a write run after it commits

Since every write and action runs in one package-owned transaction (ADR 0051), code that sends something out of the process, such as queueing a message about a row the write created, needs to wait for that commit, and an action had no way to do that beyond Laravel's `DB::afterCommit` and `ShouldQueueAfterCommit`, which follow the database connection rather than the package's write. So `WriteTransaction` opens a scope on the singleton `AfterCommit` service around every write, with or without a transaction: a callback added through `AfterCommit::register()` or `ActionContext::afterCommit()` runs once the outermost scope commits, in registration order and before the After* events and the response, a rollback drops it, an Atomic Operations batch holds every operation's callbacks until it commits, and outside any write a callback runs at once. The Symfony bundle has the same API.

## Considered Options

- **Build on `DB::afterCommit` alone.** Rejected: it follows an Eloquent connection only, so it has nothing to wait for on a persister that can't transact or on the in-memory witness, and it passes a callback's exception up into a response whose write has already committed. The package uses it only for the case below, wrapping each callback.

## Consequences

- Callbacks follow the commit, not the status code. An action that returns `ErrorResponse::fromException()` commits, so its callbacks run.
- A callback that throws is logged at error level and the rest still run, as the deferred After* hooks of an Atomic Operations batch are.
- `AfterActionEvent` fires inside an action's transaction, so it runs before that action's callbacks, while a CRUD write's After* events run after them.
- Callbacks wait for an outer application transaction. When the outermost scope commits while a connection the write's persister reports through `UsesDatabaseConnection` is still inside a transaction the application opened, as in a command or test wrapped in `DB::transaction()`, the callbacks go to that connection's `afterCommit()`: they run when the application commits, after the write's After* events, and its rollback drops them. A persister that reports no connection runs them when the package's write commits.
- The handler resets `AfterCommit` at the start of each outermost dispatch, so a scope a failed request left open never reaches the next request an Octane or queue worker serves.
