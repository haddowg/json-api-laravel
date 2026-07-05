# The request lifecycle and events

Every JSON:API request flows through one invokable controller: **negotiate → parse →
`Server::dispatch()` → render**. The Illuminate request is bridged to PSR-7 to drive core,
then core's response value object is rendered back to an Illuminate response. Around the
operation, the package dispatches **real Laravel events** (PLAN decision 10) — so
`Event::listen`, `Event::fake`, queued listeners, and event auto-discovery all work.

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
event fired. A cross-cutting subscriber is just a plain listener set: the **Symfony bundle
example's** `AuditLogSubscriber`, for instance, gates a write on an `X-Read-Only: on` header
via `ServingEvent` and records an audit entry on `AfterSaveEvent`/`AfterDeleteEvent` — the same
shape ports directly to a set of `Event::listen` calls here. (The Laravel music-catalog
workbench does not ship this subscriber — see the
[parity audit](https://github.com/haddowg/json-api-laravel/blob/main/docs/parity-audit.md) — it
is an illustration of the listener pattern, not a workbench component.)

## Before vs after semantics

- **Before events** run before persistence. Throwing from a before listener **aborts** the
  operation; the thrown exception renders through the [error pipeline](errors.md) (throw a
  `409`/`422` to veto a write).
- **After events** run after the operation. An after handler may **replace the response** by
  returning a response value object (this is how the [hook trait](lifecycle-hooks.md) shapes a
  custom response).

## Two event systems, one write

The Eloquent persister calls `$model->save()`/`$model->delete()`, so **Eloquent model events
fire untouched** on any write path. The package's JSON:API events are distinct: they carry
API operation context and defer After* work post-commit inside an atomic batch. Use model
events for persistence concerns, JSON:API events for API concerns — see
[eloquent](eloquent.md#eloquent-model-events-still-fire).

## Hooks: the per-resource sugar

Prefer to keep the logic on the resource? The [hook trait](lifecycle-hooks.md) routes the same
events to overridable methods (`beforeCreate()`, `afterSave()`, …) on the resource itself — no
listener wiring.
