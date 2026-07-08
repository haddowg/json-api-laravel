# Soft deletes

A soft-deletable resource keeps its rows on `DELETE` (setting a tombstone) instead of removing
them, and exposes **restore** and **permanent delete** as first-class operations. Opt in with a
single flag on a resource whose Eloquent model uses Laravel's `SoftDeletes` trait:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(softDeletes: true)]
final class DocumentResource extends AbstractResource
{
    public static string $type = 'documents';
    // …fields()
}
```

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Document extends Model
{
    use SoftDeletes;   // the `deleted_at` tombstone
}
```

That is the whole opt-in. It synthesizes two actions for the type and leaves the ordinary
endpoints untouched.

## The endpoints

```
DELETE /documents/1                        # soft delete — 204, recoverable
POST   /documents/1/-actions/restore       # restore    — 200, the un-trashed resource
POST   /documents/1/-actions/force-delete  # destroy    — 204, permanent
```

- **`DELETE` stays a recoverable soft delete.** The reference persister calls `$model->delete()`,
  which a `SoftDeletes` model soft-deletes — so a routine delete is never destructive. This is a
  deliberate divergence from implementations that make `DELETE` permanent once soft delete is
  enabled: here, permanent removal is *only* ever reached through the explicit `force-delete`
  action, so a client can never destroy data by accident.
- **`restore` / `force-delete` are [custom actions](actions.md)** the package generates for you —
  you write no handler. They resolve a **trashed** `{id}` (through a trashed-inclusive fetch),
  while every ordinary endpoint stays strict: a `GET`/`PATCH`/`DELETE` on a trashed id still `404`s.

Both are exposed by default; toggle them independently (and rename the abilities or URL segments)
with a configured `SoftDeletes`:

```php
use haddowg\JsonApiLaravel\Attribute\SoftDeletes;

#[AsJsonApiResource(softDeletes: new SoftDeletes(
    restore: true,
    forceDelete: false,          // no permanent-delete endpoint for this type
    restoreAbility: 'restore',   // → the model policy's restore() method
    forceAbility: 'forceDelete', // → the model policy's forceDelete() method
    restorePath: 'restore',      // the /-actions/{segment}
    forcePath: 'force-delete',
))]
```

## Authorization

Each action is gated by its own Gate ability, dispatched through the model's policy — so they map
straight onto **Laravel's native soft-delete policy convention** (`php artisan make:policy` already
scaffolds `restore()` and `forceDelete()` for a soft-deletable model), with no package-specific
registration:

```php
final class DocumentPolicy
{
    public function delete(User $user, Document $d): bool { return true; }          // the soft delete
    public function restore(User $user, Document $d): bool { return $user->can_write; }
    public function forceDelete(User $user, Document $d): bool { return $user->is_admin; }
}
```

Restore and force-delete are therefore authorized **separately** from an ordinary update or
delete — a distinction that a trash-via-`PATCH` design cannot make, since it can only reuse the
`update` ability. See [authorization](authorization.md).

## Seeing trashed state

Default collections and reads exclude trashed rows (Eloquent's global scope). Two author-declared
building blocks surface them — the same explicit surface the ecosystem uses, so the client-facing
filter key is yours to name:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\OnlyTrashed;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\WithTrashed;

public function filters(): array
{
    return [
        WithTrashed::make('withTrashed'),   // filter[withTrashed] → live + trashed
        OnlyTrashed::make('onlyTrashed'),    // filter[onlyTrashed] → only trashed
    ];
}

// A read-only `trashed` flag in the resource's meta, present only while trashed:
public function getMeta(mixed $object, JsonApiRequestInterface $request): array
{
    return \is_object($object) && \method_exists($object, 'trashed') && $object->trashed()
        ? ['trashed' => true]
        : [];
}
```

```
GET /documents                         # live rows only (default)
GET /documents?filter[onlyTrashed]=1   # the recycle bin — each carries meta.trashed: true
```

`WithTrashed`/`OnlyTrashed` are [self-applying Eloquent filters](eloquent.md); they are
Eloquent-only, so on the in-memory provider the key is undeclared and the request is a clean `400`.

## Discoverable restore link

The `restore` action renders as a `links` member on a resource — but **only when that resource is
trashed** and the requester would pass the `restore` ability. So the recycle-bin listing advertises
each row's own restore URL, while a live resource never offers a link it does not need:

```json
{
  "data": {
    "type": "documents",
    "id": "1",
    "meta": { "trashed": true },
    "links": { "restore": "https://api.example.com/documents/1/-actions/restore" }
  }
}
```

The state condition comes from the action handler implementing
`haddowg\JsonApiLaravel\Action\ConditionallyLinked` — the general seam for a state-scoped link
(a `publish` link only on a draft, a `cancel` link only on an open order). `force-delete` is
destructive, so it is never linked.

## OpenAPI

Both synthesized operations self-document in the generated [OpenAPI](openapi.md): `restore` as a
`POST` returning a `200` resource document, `force-delete` as a `POST` returning `204`, each marked
`secured` (they declare an ability). No implementation that models restore as a `PATCH` of a
writable attribute can express those as distinct operations.
