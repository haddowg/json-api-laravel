# The request lifecycle and events

Every JSON:API request flows through one invokable controller: **negotiate → parse →
`Server::dispatch()` → render**. The Illuminate request is bridged to PSR-7 to drive core,
then core's response value object is rendered back to an Illuminate response. Around the
operation, the package dispatches **real Laravel events**, so
`Event::listen`, `Event::fake`, queued listeners, and event auto-discovery all work.

Core reads the query parameters as the client sent them, re-parsed from the raw query string.
Laravel's `TrimStrings` and `ConvertEmptyStringsToNull` middleware still run, but their rewrite
of the query bag doesn't reach a JSON:API route, so `fields[artists]=` stays an empty fieldset and
`filter[name]=%20Radio` keeps its leading space.

The same goes for the request body. Core decodes a JSON:API document from the raw content, not
from the input bag the middleware rewrote, so `"title": "  Spaced  "` is stored with its spaces
and `"status": ""` stays an empty string rather than a `null` that fails validation. This
covers every write: CRUD, relationship mutations, Document-input actions and Atomic Operations
batches. A Raw-input action still reads the exact bytes off the body stream, and for a JSON
upload `getParsedBody()` now matches them.

## The events

Eighteen event classes are dispatched via Laravel's dispatcher, before and after each
operation:

| Phase | Events |
| --- | --- |
| serving | `ServingEvent` (once per request, after server resolution) |
| save | `BeforeSaveEvent`, `AfterSaveEvent` |
| create | `BeforeCreateEvent`, `AfterCreateEvent` |
| update | `BeforeUpdateEvent`, `AfterUpdateEvent` |
| delete | `BeforeDeleteEvent`, `AfterDeleteEvent` |
| relationship mutation | `BeforeRelationshipMutateEvent`, `AfterRelationshipMutateEvent` |
| reads | `AfterFetchOneEvent`, `AfterFetchCollectionEvent`, `BeforeFetchRelatedEvent`, `BeforeFetchRelationshipEvent` |
| action | `BeforeActionEvent`, `AfterActionEvent` |

Each event carries the operation context — the JSON:API `type`, the resolved server name, the
`JsonApiRequestInterface`, and the entity (or collection) in play:

```php
final class BeforeCreateEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}
}
```

`AfterFetchCollectionEvent` also exposes `$paginator`, the paginator that paged the
collection, already resolved for the request. A `MultiPaginator` arrives as the child the
client selected, so `$event->paginator->kind()` names the concrete strategy (`page`,
`offset`, `cursor` and so on). It is `null` when no pagination ran, either because the
resource's `pagination()` returned `null` or because a singular filter collapsed the
collection. The `afterFetchCollection()` resource hook doesn't receive it, so listen for the
event when a response replacement depends on it.

## Listening

Register a listener the ordinary Laravel way:

```php
use haddowg\JsonApiLaravel\Event\AfterCreateEvent;
use Illuminate\Support\Facades\Event;

Event::listen(function (AfterCreateEvent $event): void {
    Log::info("created {$event->type}", ['server' => $event->serverName]);
});
```

Queued listeners work (implement `ShouldQueue`), and `Event::fake()` lets a test assert an
event fired. A cross-cutting subscriber is just a plain listener set — the worked example is
the workbench's
[`AuditLogSubscriber`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Listeners/AuditLogSubscriber.php)
(the Laravel port of the Symfony bundle example's subscriber): registered once via
`Event::subscribe()` in the [example's wiring providers](workbench.md), it gates every write
on an `X-Read-Only: on` header via `ServingEvent` (a `403` before the operation runs) and
appends an audit entry on `AfterSaveEvent`/`AfterDeleteEvent` — one concern spanning every
type, no resource touched. `tests/Feature/MusicCatalog/AuditListenerTestCase` drives it end
to end on both provider arms: a committed write appends exactly one entry, a failed write
(a `409` hook guard, a denied ability, the read-only gate) appends none.

## Before vs after semantics

- **Before events** run before persistence, inside the
  [write's transaction](#the-writes-transaction), and after the
  [policy checks](authorization.md#policies-run-before-hooks-and-events) that guard them. Throwing from a before listener **aborts** the
  operation and rolls back anything an earlier listener wrote; the thrown exception renders
  through the [error pipeline](errors.md) (throw a `409`/`422` to veto a write).
- **After events** run after the operation. An after handler may **replace the response** by
  returning a response value object (this is how the [hook trait](lifecycle-hooks.md) shapes a
  custom response).

## The write's transaction

Every create, update, delete and relationship write runs in one transaction on its type's
persister, when the persister implements `TransactionalDataPersisterInterface` as the Eloquent
and in-memory ones do. The transaction opens before the write's first step and commits once the
persister has written:

| Write | Inside the transaction |
| --- | --- |
| create | the body's relationships, hydration, entity validation, `BeforeSaveEvent`, `BeforeCreateEvent`, the persister's `create()` and the to-many relationships applied after it |
| update | the body's relationships, hydration, entity validation, the policy check on the changed resource, `BeforeSaveEvent`, `BeforeUpdateEvent`, the persister's `update()` |
| delete | `BeforeDeleteEvent`, the persister's `delete()` |
| relationship endpoints | `BeforeRelationshipMutateEvent`, the apply, entity validation, the policy check on the changed parent |

A before listener that writes joins the transaction. A row it saves is undone if a later
listener, a validation, a policy or the persister's own write throws, so a listener that has to
save something early, for example to give a new row its key, needs no transaction of its own
to stay undoable. The After* events fire once the transaction has committed: what they read is
durable, and a throw from one fails the response without undoing the write. The response is
rendered after the commit too, so a failure while serializing it returns an error with the
write already stored.

There is no switch to turn this off. Inside an [Atomic Operations](atomic-operations.md) batch
the batch's transaction covers the write instead, and a persister that can't transact runs the
write without one. [Custom actions](actions.md#transactions) called with `POST`, `PATCH`, `PUT`
or `DELETE` run in one too.

A listener may still open its own transaction. It nests inside the write's as a savepoint, and
committing it closes that level only. Open it on the `DB` connection, which the rollback below
handles in full; a listener that calls `beginTransaction()` on the persister instance itself
must also close it, because the persister can't tell that level from the write's own. If the write fails, the rollback also closes a
transaction the listener opened and has not committed, so the connection is never left
mid-transaction for the next request an Octane or queue worker serves. Eloquent keeps no
identity map, so there is nothing to clear, but a model instance the request changed keeps its
in-memory attributes after a rollback; read it again before using it.

Since 2.17 a before listener can't keep a row of its own when it throws. Whatever it, or any
earlier listener, saved is rolled back with the rest of the write. A listener that rejects a
write and needs a record of the rejection has to make it outside the transaction: after the
response, from a terminating callback registered with `app()->terminating()`, or on a second
database connection. An [action](actions.md#recording-a-rejection) can instead return its
error as a response, which commits.

### Running code after the commit

A rollback undoes only what went through the connection. A queued job or listener, a mail or an
HTTP call made from a before listener has already left. Register that work with the
`AfterCommit` service instead, and it runs once the write commits:

```php
use haddowg\JsonApiLaravel\DataPersister\AfterCommit;
use haddowg\JsonApiLaravel\Event\BeforeCreateEvent;

final readonly class QueueWelcomeMail
{
    public function __construct(private AfterCommit $afterCommit) {}

    public function handle(BeforeCreateEvent $event): void
    {
        $user = $event->entity;

        $this->afterCommit->register(static fn() => SendWelcomeMail::dispatch($user));
    }
}
```

`register()` works from any code the write runs: a Before* listener or [hook](lifecycle-hooks.md), a
persister, or an [action](actions.md#running-code-after-the-commit), which has
`$context->afterCommit()` for the same thing. The callbacks run in the order they were added,
after the commit and before the After* events and the response, so an After* listener sees
their effects. A write that rolls back drops them. A write whose persister can't transact runs
them once it returns, and an [Atomic Operations](atomic-operations.md) batch holds them until
the whole batch commits. Called outside any write, from an After* listener for instance, `register()`
runs the callback at once.

A write can itself run inside a transaction your application opened, for example when a
command calls the API from inside `DB::transaction()`. The write's commit then only closes a
savepoint, so the callbacks wait for the application's transaction: when any database
connection Laravel has resolved is still inside a transaction, the package hands them to that
connection's `afterCommit()`. They run when the application commits, after the write's After*
events, and the application's rollback drops them. The check doesn't know which connection the
write used, so an open transaction on an unrelated connection holds the callbacks too, and its
rollback drops them. A test using `DatabaseTransactions` or `RefreshDatabase` is not held back:
Laravel's test transaction manager ignores the test's own transaction, so callbacks run when
the write commits, as they do in production.

The write has committed by the time a callback runs, so a callback that throws is logged at
error level, the rest still run and the response goes out unchanged. `AfterCommit` is a
singleton that the package clears at the start of each request, so an Octane or queue worker
never carries a callback into the next one.

For queued work, Laravel's own after-commit support is the alternative: `after_commit` on the
queue connection, or `ShouldQueueAfterCommit` on the job or listener, holds it until the
database connection's transaction commits and drops it on a rollback. That follows the
connection, so it covers Eloquent writes, while `AfterCommit` follows the package's write on
any persister and takes any callable. Work that only needs the commit to have happened can
also go in an After* listener.

## Two event systems, one write

The Eloquent persister calls `$model->save()`/`$model->delete()`, so **Eloquent model events
fire untouched** on any write path, inside the write's transaction. The package's JSON:API
events are distinct: they carry API operation context, and the After* events fire once the
write, or the atomic batch, has committed. Use model
events for persistence concerns, JSON:API events for API concerns — see
[eloquent](eloquent.md#eloquent-model-events-still-fire).

## Hooks: the per-resource sugar

Prefer to keep the logic on the resource? The [hook trait](lifecycle-hooks.md) routes the same
events to overridable methods (`beforeCreate()`, `afterSave()`, …) on the resource itself — no
listener wiring.
