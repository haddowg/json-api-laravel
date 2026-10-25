# Authorization

Authorization is **policy-first** and Laravel-native (PLAN decision 7). The package resolves
the model's Gate-registered policy and checks it at each lifecycle point **with the loaded
model in hand, before persistence** — the entity-level check the Symfony firewall cannot
express. Where the Symfony bundle uses ExpressionLanguage `security:` strings, this package
uses policies, ability names, and a dedicated API-policy class. Timing is on the pristine
subject, before hydration
([ADR 0004](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0004-write-authorization-is-pristine-subject-and-timed-before-hydration.md)),
and an update is checked again on the changed resource before it is saved
([ADR 0046](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0046-the-update-gate-checks-the-stored-and-the-changed-resource.md)).

## The default: the model's policy

By default a type is authorized through the model's Gate policy at the natural ability per
operation:

| Operation | Ability |
| --- | --- |
| list (`GET /albums`) | `viewAny` |
| read (`GET /albums/{id}`) | `view` |
| create (`POST /albums`) | `create` |
| update (`PATCH /albums/{id}`) | `update` |
| delete (`DELETE /albums/{id}`) | `delete` |

Register a policy the usual Laravel way (`Gate::policy(Album::class, AlbumPolicy::class)` or
auto-discovery) and it just works. **No policy registered → no check**: the type is inert,
so the package adds no authorization you didn't ask for. That only covers these convention
abilities. An ability you declare (a renamed ability under `abilities:`, a relation's
`security(read:/mutate:)` or an action's `ability`) is always checked, so if no policy method
or `Gate::define()` answers for it, the request is denied rather than let through. A denial is a JSON:API `403` with the
code `ACCESS_DENIED` (an unauthenticated request that needs identity is a `401` with
`AUTHENTICATION_REQUIRED`), rendered before any persistence. See
[errors](errors.md#the-mapping-pipeline).

`actingAs()` in tests sets the user natively — see
[multi-server-and-testing](multi-server-and-testing.md).

## Updates are checked before and after the change

An update has to pass its gate twice. The `update` ability is checked on the resource as
stored, before validation and before anything is applied, so a caller it refuses gets a `403`
and no validation errors. It is checked again on the resource as changed, after the body's
attributes and relationships are applied and the before-update hooks have run, just before the
save. A refusal there is a `403` and nothing is saved. With a policy like "only the owner may
update", an owner can edit their post but can't hand it to someone else, and nobody can take
someone else's.

The same applies to `PATCH`, `POST` and `DELETE` on `/relationships/{rel}`: the relation's
mutate ability (or else the parent's `update`) is checked on the parent before the change and
again on the changed parent before it commits. Atomic Operations `update` operations, on a
resource or a relationship, go through the same checks. A relation's mutate ability set in a
whole-resource update is also checked again on the changed resource.

The second check runs inside a transaction, so a refusal rolls back what was applied. A custom
persister that doesn't implement `TransactionalDataPersisterInterface` has already applied a
relationship change when the second check refuses it, so implement it if you rely on this
check. A policy that should allow a transfer has to allow the new state too, for example by
also letting an admin through.

## Overriding abilities per operation

`#[AsJsonApiResource(abilities: […])]` renames or disables the ability per operation, keyed by
`Operation` case value:

```php
use haddowg\JsonApiLaravel\Operation\Operation;

#[AsJsonApiResource(abilities: [
    Operation::Update->value => 'curate',          // check a custom ability name
    Operation::Delete->value => 'deletePlaylist',
    Operation::FetchOne->value => false,           // disable the check for reads
])]
```

- a **string** is the Gate ability checked (so `Gate::define('curate', …)` works too);
- **`false`** disables the check for that operation;
- **`true`** documents the operation as secured (an external firewall/middleware enforces it)
  **without** the package's Gate enforcing it — projected into OpenAPI as secured + `401`, the
  byte-compat twin of the bundle's documentation-only security marker.

The example's `artists` uses `abilities: ['read' => false, 'list' => false]` to make both
reads fully public (its OpenAPI projection emits `security: []`).

## A dedicated API policy class

`policy:` names a policy class invoked **directly** (container-resolved, honouring its
`before()`), leaving the application's `Gate::policy()` mapping untouched — the
provider-agnostic seam a POPO-backed type uses:

```php
#[AsJsonApiResource(policy: PlaylistApiPolicy::class, abilities: [
    Operation::Update->value => 'curate',
    Operation::Delete->value => 'deletePlaylist',
])]
final class PlaylistResource extends AbstractResource { /* … */ }
```

```php
final class PlaylistApiPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->is_admin === true ? true : null;   // admin bypass
    }

    public function curate(User $user, object $playlist): bool { /* owner check */ }
    public function deletePlaylist(User $user, object $playlist): bool { return false; } // admin-only
    public function inspectOwner(User $user, object $playlist): bool { return false; }   // per-relation
}
```

This is the "policies replace the Voter" demonstrator: the subject is typed `object`, so the
same policy authorizes both the Eloquent model and an in-memory POPO. The example's
`PlaylistApiPolicy` carries an **API-distinct method** (`curate` for update, `deletePlaylist`
for delete) — the point of decision 7.

## Relationships

A relationship mutation defaults to the parent's `update` ability. Override the ability for a
specific relation's reads with `->security(read: …)`:

```php
BelongsTo::make('owner', 'users')->security(read: 'inspectOwner');
```

The example's `playlists.owner` gates its related/relationship reads on `inspectOwner`
(admin-only), while the sibling `publicOwner` (same FK, curated type) stays open.

The same ability decides what the relationship shows wherever the parent is rendered: on a
fetched resource, in a collection, and on a resource in `included`. It is checked once per
parent, so a caller without it sees the relationship's `links` and no `data` or `meta`, and
nothing reaches `included` through it. A relation's read ability is its read permission, which
replaces the related type's own gates for that relation; see
[relation read permissions](#relation-read-permissions).

An attribute flattened with `on()` writes onto the related row at the end of its chain. That
write needs the related type's `update` ability (or its renamed ability) on that row, checked
after the body's relationships are applied, so a body can't link a row and write onto it
unless the caller may update that row. A refusal is a `403` and nothing is saved.

`->security(mutate: …)` replaces the parent's `update` ability for that relation's
`/relationships/{rel}` writes. The same ability also applies when the relation is set inside a
whole-resource create or update (including an Atomic Operations `add`/`update`), checked against
the parent after the create/update ability passes.

## Related rows and their own type's policy

Rows of another type that a response renders pass that type's own read gates, so a type's
policy can't be read around by including it from a type that is open. A relation that declares
its own [read permission](#relation-read-permissions) uses that instead.

A single related row passes its type's `view` ability (or its rename), the check
`GET /{type}/{id}` makes on that row. That covers a to-one relationship's linkage and its
`included` resource, the to-one related and relationship endpoints, and attributes flattened
with `on()`. A row `view` refuses reads as absent: the to-one renders `null`, nothing reaches
`included`, a flattened attribute read through it is `null`, and a write through it is a `422`.

A to-many is a list, and a list shows exactly what its query returns, the same as
`GET /{type}`. Its linkage, its members in `included`, its related and relationship endpoint
pages, its `?withCount` total and its Relationship Queries window all come from the related
type's query, with its Eloquent global scopes or its
[scoping provider](custom-data-providers.md#a-provider-that-scopes-its-rows). `view` is not
asked row by row, so a page is never cut short and every count matches the rows a client can
page through. Rows that must never be listed belong in a query scope; `view` governs single
rows.

What a to-many does check is the related type's collection gate, the `viewAny` ability (or its
rename, or the dedicated policy) that `GET /{type}` runs. When it refuses the caller, the
relationship renders only its `links`, with no `data`, no `meta` and nothing in `included`, and
its related and relationship endpoints answer `403` the way `GET /{type}` does. A polymorphic
to-many is withheld when any of its member types refuses. A type with no policy and no defined
ability is not checked, exactly as for `GET /{type}`.

This is a change for apps that relied on `?include` to read a single row its `view` policy
refuses, or a to-many of a type their `viewAny` refuses. Those are now hidden.

## Relation read permissions

Two things decide what a relation shows. **Permission** is whether this caller may see the
relation's data at all. **Scoping** is which rows exist: Eloquent global scopes and scoping
providers. Scoping always applies, may leave no rows at all, and every count follows it.

By default the permission is the related type's own: its `viewAny` gate for a to-many and its
`view` gate for each to-one row, as described above. A relation can declare its own permission
instead, which then replaces the related type's gates for that relation only. In order of
precedence:

1. `->security(read: 'ability')`, checked against the parent.
2. A `view{Relation}` ability on the parent type's policy (its dedicated `policy:` class or the
   model's Gate policy) or defined on the Gate, checked against the parent. For a `tags`
   relation on posts that is `PostPolicy::viewTags($user, $post)`. It counts only when the
   method or ability exists, so a policy without it leaves the related type's gates in charge.
   A name that would collide with a standard ability, such as a relation named `any`, is
   skipped.
3. `->security(read: true)` or `->security(read: false)`, which declare the relation without
   enforcing anything in the app.

When the permission allows, the relation's data renders under scoping alone, even if the
related type's `viewAny` or `view` would refuse: its linkage, its `included` resources, both of
its endpoints, `?withCount`, the Relationship Queries window and `on()` through a to-one. When it
refuses, the relationship renders only its `links`, its related and relationship endpoints answer
`403`, and an `on()` attribute through a refused to-one reads `null` and can't be written (a
`422`). Writes are not affected.

Exposing a relation of a type the caller can't list. Here `users` refuses `viewAny` to
non-admins, but anyone who can view a team may see its members:

```php
final class TeamPolicy
{
    public function viewMembers(User $user, Team $team): bool
    {
        return $team->members->contains($user);
    }
}
```

`GET /teams/1?include=members` then lists the members the `users` scope admits, with exact
counts, while `GET /users` stays refused.

Blocking one relation of a type the caller can list. Here `invoices` allows `viewAny`, but a
customer's invoices are for its account managers only:

```php
final class CustomerPolicy
{
    public function viewInvoices(User $user, Customer $customer): bool
    {
        return $customer->manager_id === $user->id;
    }
}
```

Everyone else sees only the `invoices` relationship's links, and `GET /customers/1/invoices`
answers `403`.

## Custom actions

A custom action declares its own ability on the attribute (not on the type):

```php
#[AsJsonApiAction(type: 'albums', path: 'reissue', ability: 'reissueAlbum')]
```

The ability is checked against the resolved entity (resource scope) or the resource-class
token (collection scope) before the handler runs. See [actions](actions.md).

## OpenAPI security

The document's security is config- and attribute-declared (not derived from runtime
enforcement, so the projection is stable): `jsonapi.openapi.security` declares the named
schemes and the document-level default requirement; `abilities`/relation `security`/action
`ability` mark operations as secured. The example declares a `bearer` scheme + a default
requirement, so a secured operation inherits a `401`. See
[openapi](openapi.md#security) and [configuration](configuration.md#openapi).
