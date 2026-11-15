# Every single write runs in one package-owned transaction

A single create, update, delete or relationship write runs in one transaction on its type's persister, opened before the first write step so the body's relationships, hydration, entity validation, every Before* event and the policy checks on the changed resource are inside it, and committed before the After* events fire; an action called with POST, PATCH, PUT or DELETE runs in one on its mount type's persister (else its input type's) through `AfterActionEvent`. Before this, create opened its transaction only around the persister's `create()` and the deferred to-many applies, so a row a `BeforeSaveEvent` or `BeforeCreateEvent` listener saved stayed when the create failed, and delete, the relationship endpoints' before event and actions had none. There is no opt-out, an Atomic Operations batch now owns the transaction for its operations rather than each opening a savepoint inside it, a persister that can't transact writes as before, and the Symfony bundle does the same (its ADR 0086).

## Consequences

- `rollback()` must undo everything since the matching `beginTransaction()`, including a transaction a listener opened inside it and left open. The Eloquent persister records the connection's level when it begins and rolls back to it.
- A `202` accept commits what the Before* listeners and the persister wrote; the job owns the change itself.
- A queued job dispatched or an external call made inside the transaction is not undone by a rollback unless the queue waits for the commit, and a long-running collection action holds one transaction for all its rows.
