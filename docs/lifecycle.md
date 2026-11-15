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
  [write's transaction](#the-writes-transaction). Throwing from a before listener **aborts** the
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
| update | the body's relationships, hydration, entity validation, `BeforeSaveEvent`, `BeforeUpdateEvent`, the policy check on the changed resource, the persister's `update()` |
| delete | `BeforeDeleteEvent`, the persister's `delete()` |
| relationship endpoints | `BeforeRelationshipMutateEvent`, the apply, entity validation, the policy check on the changed parent |

A before listener that writes joins the transaction. A row it saves is undone if a later
listener, a validation, a policy or the persister's own write throws, so a listener that has to
save something early, for example to give a new row its key, needs no transaction of its own
to stay undoable. The After* events fire once the transaction has committed: what they read is
durable, and a throw from one fails the response without undoing the write.

There is no switch to turn this off. Inside an [Atomic Operations](atomic-operations.md) batch
the batch's transaction covers the write instead, and a persister that can't transact runs the
write without one. [Custom actions](actions.md#transactions) called with `POST`, `PATCH`, `PUT`
or `DELETE` run in one too.

A listener may still open its own transaction. It nests inside the write's as a savepoint, and
committing it closes that level only. If the write fails, the rollback also closes a
transaction the listener opened and has not committed, so the connection is never left
mid-transaction for the next request an Octane or queue worker serves. Eloquent keeps no
identity map, so there is nothing to clear, but a model instance the request changed keeps its
in-memory attributes after a rollback; read it again before using it.

A rollback undoes only what went through the connection. A queued job or listener, a mail or an
HTTP call made from a before listener has already left. Make queued work wait for the commit,
with `after_commit` on the queue connection or `ShouldQueueAfterCommit` on the job or listener,
so a rollback drops it, or do the work in an After* listener.

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
