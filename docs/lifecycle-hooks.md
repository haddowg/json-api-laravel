# Lifecycle hooks

The hook trait is per-resource sugar over the [lifecycle events](lifecycle.md): implement
`ResourceLifecycleHooksInterface`, `use ResourceLifecycleHooksTrait`, and override just the
methods you need. The trait routes the dispatched events to your resource, so the logic lives
next to the fields it concerns — no listener wiring (PLAN decision 10).

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
| `afterDelete` | `(object $entity, HookContext $context)` | `DataResponse\|NoContentResponse\|null` |
| `beforeRelationshipMutate` | `(object $parent, HookContext $context)` | `void` |
| `afterRelationshipMutate` | `(object $parent, HookContext $context)` | `?IdentifierResponse` |
| `afterFetchOne` | `(object $entity, HookContext $context)` | `?DataResponse` |
| `afterFetchCollection` | `(array $items, HookContext $context)` | `?DataResponse` |

`ResourceLifecycleHooksTrait` gives every method a no-op default, so you override only what
you need.

## Before vs after

- **A before hook** runs before persistence. Mutating the entity there is durable (the example
  derives `slug`/`externalId` in `beforeCreate`). **Throwing aborts** the operation and
  renders through the [error pipeline](errors.md) — the clean way to veto a write
  (`beforeDelete` throwing a `409`).
- **An after hook** runs after the operation and, by returning a response value object, **may
  replace the response** — reshape the rendered document, or return a `204` from
  `afterDelete`. Returning `null` keeps the default response.

## `HookContext`

Every hook receives a `HookContext` carrying the operation's request, server name, and type —
the same context the events expose, so a hook can branch on the request (e.g. an authed-only
side effect).

## Hooks vs events vs model events

- **Hooks** — logic that belongs to one resource; the ergonomic default.
- **[Events](lifecycle.md)** — cross-cutting concerns across many types (audit, metrics),
  queued work, or `Event::fake()` in tests.
- **[Eloquent model events](eloquent.md#eloquent-model-events-still-fire)** — persistence
  concerns that must fire on any write path, not just API-driven ones.
