# Authorization

Authorization is **policy-first** and Laravel-native (PLAN decision 7). The package resolves
the model's Gate-registered policy and checks it at each lifecycle point **with the loaded
model in hand, before persistence** — the entity-level check the Symfony firewall cannot
express. Where the Symfony bundle uses ExpressionLanguage `security:` strings, this package
uses policies, ability names, and a dedicated API-policy class. Timing is on the pristine
subject, before hydration
([ADR 0004](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0004-write-authorization-is-pristine-subject-and-timed-before-hydration.md)).

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
auto-discovery) and it just works. **No policy registered → no check** — the type is inert,
exactly like the bundle without security-core. A denial is a JSON:API `403` (an
unauthenticated request that needs identity is a `401`), rendered before any persistence.

`actingAs()` in tests sets the user natively — see
[multi-server-and-testing](multi-server-and-testing.md).

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
