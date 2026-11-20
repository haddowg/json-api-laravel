# Lifecycle hooks

The hook trait is per-resource sugar over the [lifecycle events](lifecycle.md): implement
`ResourceLifecycleHooksInterface`, `use ResourceLifecycleHooksTrait`, and override just the
methods you need. The trait routes the dispatched events to your resource, so the logic lives
next to the fields it concerns — no listener wiring.

## Declaring hooks

```php
use haddowg\JsonApiLaravel\Hook\HookContext;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksTrait;

final class PlaylistResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public function beforeCreate(object $entity, HookContext $context): void
    {
        // derive a read-only slug + stamp an externalId before the persister flushes
        $title = Accessor::get($entity, 'title');
        Accessor::set($entity, 'slug', \Str::slug(\is_string($title) ? $title : ''));
    }

    public function beforeDelete(object $entity, HookContext $context): void
    {
        $tracks = Accessor::get($entity, 'tracks');
        if (\is_countable($tracks) && \count($tracks) > 0) {
            throw new ConflictHttpException('Cannot delete a playlist that still has tracks.');
        }
    }
}
```

Both are from the example's `playlists` — a mutating before-create hook and a before-delete
guard.

## The full hook set

| Hook | Signature | Return |
| --- | --- | --- |
| `beforeSave` | `(object $entity, bool $creating, HookContext $context)` | `void` |
| `afterSave` | `(object $entity, bool $creating, HookContext $context)` | `?DataResponse` |
| `beforeCreate` | `(object $entity, HookContext $context)` | `void` |
| `afterCreate` | `(object $entity, HookContext $context)` | `?DataResponse` |
| `beforeUpdate` | `(object $entity, object $original, HookContext $context)` | `void` |
| `afterUpdate` | `(object $entity, HookContext $context)` | `?DataResponse` |
| `beforeDelete` | `(object $entity, HookContext $context)` | `void` |
| `afterDelete` | `(object $entity, HookContext $context)` | `DataResponse\|MetaResponse\|NoContentResponse\|null` |
| `beforeRelationshipMutate` | `(object $parent, HookContext $context)` | `void` |
| `afterRelationshipMutate` | `(object $parent, HookContext $context)` | `?IdentifierResponse` |
| `afterFetchOne` | `(object $entity, HookContext $context)` | `?DataResponse` |
| `afterFetchCollection` | `(array $items, HookContext $context)` | `?DataResponse` |

`ResourceLifecycleHooksTrait` gives every method a no-op default, so you override only what
you need.

## Before vs after

- **A before hook** runs before persistence, inside the
  [write's transaction](lifecycle.md#the-writes-transaction), and only for a caller the
  [policy checks](authorization.md#policies-run-before-hooks-and-events) let through. Mutating the entity there is
  durable (the example derives `slug`/`externalId` in `beforeCreate`), and so is a row it
  saves, unless a later step throws. **Throwing aborts** the operation, rolls back what the
  hook and the earlier hooks wrote, and renders through the [error pipeline](errors.md), the
  clean way to veto a write (`beforeDelete` throwing a `409`). A hook that has to record the
  rejection makes the record outside the transaction (see
  [lifecycle](lifecycle.md#the-writes-transaction)). A hook that opens its own transaction on the
  `DB` connection needs nothing more; one that calls `beginTransaction()` on the persister
  instance itself must close it, because the persister can't tell that level from the
  write's own. Work that must wait for the commit, such as queueing a job, goes to the
  [`AfterCommit`](lifecycle.md#running-code-after-the-commit) service, which the resource
  can take in its constructor.
- **After hooks and the response** come after the commit, so a failure while serializing the
  response returns an error with the write already stored.
- **An after hook** runs after the operation and, by returning a response value object, **may
  replace the response** — reshape the rendered document, or return a `204` from
  `afterDelete`. Returning `null` keeps the default response. A replacement `DataResponse`
  that renders other resources has their relations loaded through their providers as a
  read would, so its linkage and `included` are scoped the same.
  `afterDelete` may also answer a meta-only `MetaResponse`, which is filled with the
  [delete's typed meta](openapi.md#typed-document-meta).

## `HookContext`

Every hook receives a `HookContext` carrying the operation's request, server name, and type —
the same context the events expose, so a hook can branch on the request (e.g. an authed-only
side effect). It is a readonly value object with public properties, not getters:
`$context->request`, `$context->serverName`, `$context->type`, and for the relationship
hooks `$context->relation`, `$context->linkage` and `$context->mode`.

`$context->writtenMeta()`, and `writtenMeta()` on the write events a listener receives, return
the typed `meta` the write sent: `->document` for its top-level `meta` and `->resource` for its
`data.meta`, each validated against what the type declares in `requestDocumentMeta()` and
`resourceMeta()` (see [typed request meta](openapi.md#typed-request-and-resource-object-meta)).
They are empty for a write that sent none. `->document` is also empty when the type declares no
request meta for the write, as an action's `requestMeta()` is; the raw `meta` stays readable on
`$context->request`.

## Hooks vs events vs model events

- **Hooks** — logic that belongs to one resource; the ergonomic default.
- **[Events](lifecycle.md)** — cross-cutting concerns across many types (audit, metrics),
  queued work, or `Event::fake()` in tests. The workbench's
  [`AuditLogSubscriber`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Listeners/AuditLogSubscriber.php)
  is the worked example — an audit trail plus a read-only gate from one subscriber.
- **[Eloquent model events](eloquent.md#eloquent-model-events-still-fire)** — persistence
  concerns that must fire on any write path, not just API-driven ones.
