# The request lifecycle and events

Every JSON:API request flows through one invokable controller: **negotiate → parse →
`Server::dispatch()` → render**. The Illuminate request is bridged to PSR-7 to drive core,
then core's response value object is rendered back to an Illuminate response. Around the
operation, the package dispatches **real Laravel events**, so
`Event::listen`, `Event::fake`, queued listeners, and event auto-discovery all work.

Core reads the query parameters as the client sent them, re-parsed from the raw query string.
Laravel's `TrimStrings` and `ConvertEmptyStringsToNull` middleware still run, but their rewrite
of the query bag doesn't reach a JSON:API route, so `fields[artists]=` stays an empty fieldset and
`filter[name]=%20Radio` keeps its leading space ([ADR 0030](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0030-json-api-query-parameters-bypass-input-middleware.md)).

The same goes for the request body. Core decodes a JSON:API document from the raw content, not
from the input bag the middleware rewrote, so `"title": "  Spaced  "` is stored with its spaces
and `"status": ""` stays an empty string rather than a `null` that fails validation. This
covers every write: CRUD, relationship mutations, Document-input actions and Atomic Operations
batches. A Raw-input action still reads the exact bytes off the body stream, and for a JSON
upload `getParsedBody()` now matches them ([ADR 0031](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0031-json-api-request-bodies-bypass-input-middleware.md)).

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
