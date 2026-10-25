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

See [permission or scope?](#permission-or-scope) for both cases worked through.

## Scoping access per user

A scope decides which rows of a type exist for the current user. Write it once, on the type,
and everything that reaches the type honours it. The examples below are the test fixtures in
`tests/Fixtures/OwnedRows`, where a project has tasks and each task has an owner.

### With an Eloquent global scope

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

final class OwnedByCurrentUser implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('owner_id'), Auth::id());
    }
}

#[ScopedBy(OwnedByCurrentUser::class)]
final class Task extends Model
{
    protected $table = 'or_tasks';
}
```

Eloquent applies the scope to every query for the model, including relation reads, so for a
signed-in user it limits:

- the rows of `GET /tasks` and its `meta.page.total`;
- every relationship that reaches `tasks`: the pages and totals of the related and
  relationship endpoints, linkage, `included` resources, `?withCount`, and the Relationship
  Queries window and its count.

Project 1 holds three tasks, and user 1 owns two of them. The response is trimmed to the parts
that matter here:

```http
GET /api/projects/1?include=tasks&withCount=tasks
Accept: application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/countable/"
```

```json
{
  "data": {
    "type": "projects",
    "id": "1",
    "attributes": { "name": "Launch" },
    "relationships": {
      "tasks": {
        "links": {
          "self": { "href": "http://localhost/api/projects/1/relationships/tasks" },
          "related": { "href": "http://localhost/api/projects/1/tasks" }
        },
        "meta": { "total": 2 },
        "data": [
          { "type": "tasks", "id": "1" },
          { "type": "tasks", "id": "3" }
        ]
      }
    }
  },
  "included": [
    { "type": "tasks", "id": "1", "attributes": { "title": "Draft" } },
    { "type": "tasks", "id": "3", "attributes": { "title": "Ship" } }
  ]
}
```

`GET /api/projects/1/tasks?withCount=_self_` answers the same two tasks with `meta.total` of 2,
and a `relatedQuery[tasks][filter]` narrows the user's tasks rather than all of them.

### With a scoping provider

A type that can't use a global scope, because it isn't an Eloquent model, gets the same
behaviour from a [provider that scopes its rows](custom-data-providers.md#a-provider-that-scopes-its-rows).
Narrow the rows before running the request's criteria, so filters, sorting, paging and counts
all work on the user's rows:

```php
final class OwnTasksOnly extends AbstractDataProvider
{
    /** @param array<array-key, TaskRecord> $tasks */
    public function __construct(private readonly array $tasks) {}

    public function supports(string $type): bool
    {
        return $type === 'tasks';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $task = $this->tasks[$id] ?? null;

        return $task !== null && $this->owns($task) ? $task : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->tasks)->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        $members = $relation->readValue($parent, $request);

        return $this->query(is_iterable($members) ? $members : [])->fetchCollection($relatedType, $criteria);
    }

    private function query(iterable $rows): InMemoryDataProvider
    {
        $owned = [];
        foreach ($rows as $row) {
            if ($row instanceof TaskRecord && $this->owns($row)) {
                $owned[$row->id] = $row;
            }
        }

        return new InMemoryDataProvider('tasks', $owned, identify: static fn(object $task): string => $task instanceof TaskRecord ? $task->id : '');
    }

    private function owns(TaskRecord $task): bool
    {
        return $task->owner_id === (string) Auth::id();
    }
}
```

Register it with `JsonApi::provider(new OwnTasksOnly($tasks))`. The same request answers the
same document as with the global scope.

### Permission or scope?

`viewAny`, `view` and a relation's read permission decide whether data shows at all. A refusal
hides the whole collection, the whole row or the whole relation. A scope decides which rows
exist. It can leave zero rows, and it is the only thing that should hide individual rows from a
list, because only the query can keep pages full and counts exact.

Exposing a relation of a type the caller can't list. Here only an admin may list tasks through
`GET /tasks`, but anyone who can read may see a project's tasks:

```php
final class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }
}

final class ProjectPolicy
{
    public function viewTasks(User $user, object $project): bool
    {
        return $user->can_read;
    }
}
```

`GET /tasks` is a `403` for a reader, while `GET /projects/1?include=tasks` lists the tasks the
scope admits, with exact counts.

Blocking one relation of a type the caller can list. With no `TaskPolicy`, `GET /tasks` lists the
user's tasks for anyone, but the same `ProjectPolicy::viewTasks()` refuses a user who can't read.
That user sees only the project's `tasks` links, and `GET /projects/1/tasks` answers `403`.

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
