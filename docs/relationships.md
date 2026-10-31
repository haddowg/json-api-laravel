# Relationships

Declare a relation on a resource and you get its linkage, its `self`/`related` links, the
related and relationship endpoints, `?include`, and (for a countable relation) `?withCount` —
all by convention. The relation name maps to an Eloquent relationship method on the model. The
relation DSL is core's — see core
[relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md); this page covers
the Laravel behaviour around it.

## Declaring relations

Relations are fields. To-one is `BelongsTo`; to-many is `HasMany` / `BelongsToMany`;
polymorphic is `MorphTo` / `MorphToMany`:

```php
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;

BelongsTo::make('artist', 'artists');            // to-one → the `artists` type
HasMany::make('tracks', 'tracks')->countable();  // to-many, countable
```

The second argument is the **target type** (the JSON:API type of the related resource), which
need not equal the relationship method name — `BelongsTo::make('publicOwner', 'public-profiles')->storedAs('owner')`
reads the `owner` relation but renders the curated `public-profiles` type (one model, two
types).

## The endpoints

For each relation you get, gated by exposure flags:

```
GET    /api/albums/1/tracks                    # the RELATED resource(s)
GET    /api/albums/1/relationships/tracks      # the linkage
PATCH  /api/albums/1/relationships/artist      # replace a to-one
POST   /api/albums/1/relationships/tracks      # add to a to-many
DELETE /api/albums/1/relationships/tracks      # remove from a to-many
```

A relationship write applies the change inside the persister's transaction and, before it
commits, validates the changed parent against its
[entity-level constraints](validation.md#after-a-relationship-write). A violation is a `422`
that rolls the change back.

## The related endpoint's target must be registered on the server

`GET /api/albums/1/artist` returns an `artists` resource object as primary data, so the
server serving it needs a serializer for `artists` and the OpenAPI document needs its
field inventory. A relation that exposes its related endpoint to a type the server does
not register is therefore a configuration error, and `jsonapi:optimize` fails the deploy
over it (the OpenAPI export refuses too, whichever runs first). Three ways out:

- register the related type on that server as well;
- point the relation at a **reduced second type** that is registered — the workbench does
  this with `public-profiles` beside the admin-only `users`, both backed by the same
  `User` row;
- declare the relation **linkage-only** with `withoutRelatedEndpoint()`. The linkage
  `{"type": "users", "id": "1"}` asserts no shape, so an unregistered target is fine
  there; the `related` link is omitted so nothing points at the 404. Give it `->exampleId('1')`
  so its OpenAPI examples have an id to show, since the server has no `users` `Id` example
  to take one from.

A type reached only as linkage never needs registering. It is exposing the endpoint that
makes the claim.

## Linkage, links, and load state

Every relation renders `self`/`related` links by convention (`withoutLinks()` opts out).
Whether linkage `data` is rendered depends on load state: a lazy to-many emits links-only
without forcing a fetch (`dataOnlyWhenLoaded()`), driven by a storage-aware load-state seam —
on Eloquent, `relationLoaded()`. An empty to-one renders `data: null`.

## `?include`

Compound documents assemble through a provider-agnostic batcher over the SPI (not top-level
`with()` — [eloquent](eloquent.md#include-batching--spi-not-with)):

```
GET /api/albums/1?include=artist,tracks
GET /api/users/1?include=playlists.owner
```

- **Default includes** — a resource renders includes for an id request with no `?include` via
  `getDefaultIncludedRelationships()` (the example's `albums` rides `artist`). An explicit
  `?include` suppresses the default.
- **Depth cap** — `?include` depth is bounded by `jsonapi.max_include_depth` (a resource
  overrides it); the example caps at 2.
- **Path whitelist** — `getAllowedIncludePaths()` restricts the dotted paths from a root type
  (the example's `users` allows `playlists`, `playlists.owner`, `library` but not
  `playlists.tracks` → `400`).
- **Per-relation opt-out** — `cannotBeIncluded()` keeps `?include=favoritable` a `400` while
  the related/relationship endpoints still render (the example's `favorites.favoritable`).

## `?withCount` (the Countable profile)

A `countable()` to-many can report its size from the primary request, pushed down as a `COUNT`
(no materialisation):

```
GET /api/albums/1?withCount=tracks     # → relationships.tracks.meta.count
```

Opt in by negotiating the Countable profile. A relation that is not `countable()` (the
example's count-free `orderedTracks` pivot) returns a `400` to `?withCount`.

## Relation-scoped filters, sorts, and pagination

A relation may declare filters/sorts/pagination that apply only to *its* endpoint, distinct
from the related resource's own set:

```php
BelongsTo::make('artist', 'artists')
    ->withFilters(Where::make('name', 'name'));

HasMany::make('tracks', 'tracks')
    ->paginate(PagePaginator::make()->withDefaultPerPage(2))
    ->withFilters(Where::make('longerThan', 'length_seconds', '>')->integer())
    ->withSorts(SortByField::make('duration', 'length_seconds'))
    ->countable();
```

## The Relationship Queries profile

Order and narrow a relation's linkage from the **primary** request (not the related
endpoint), opt-in by negotiating the profile:

```
GET /api/albums/1?relatedQuery[tracks][sort]=duration&relatedQuery[tracks][filter][longerThan]=200
```

Windowing per parent uses the SQL push-down described in
[eloquent](eloquent.md#windowed-relationship-queries--sql-push-down-only). A relationship
endpoint query it cannot honour is a `400`, never silently ignored.

## Pivot (`belongsToMany`) data

A `BelongsToMany` over a join table with columns renders them as `meta.pivot`, validates and
upserts them through linkage `meta`, and exposes author-declared pivot filters. The example's
`playlists.orderedTracks` carries `position`/`weight`/`addedAt`:

```php
BelongsToMany::make('orderedTracks', 'tracks')
    ->fields(
        Integer::make('position')->required()->min(1),
        Integer::make('weight')->compareWith('position', Comparison::GreaterThanOrEqual),
        DateTime::make('addedAt')->storedAs('added_at')->readOnly(),
    )
    ->withFilters(
        Where::make('position', 'pivot.position')->integer(),
        Where::make('weight', 'pivot.weight')->integer(),
    )
    ->paginate(PagePaginator::make()->withDefaultPerPage(2));
```

`position` is required-on-create, `weight` is a second writable pivot field constrained
`weight >= position`, and `addedAt` is server-owned (`readOnly`). Pivot fields are merged into
the payload **before validation**, so cross-field rules see them.

`meta.pivot` renders on the related endpoint, the relationship endpoint and the `200` linkage a
relationship mutation answers with. Only a provider that reads pivot rows supplies it, so the
in-memory provider renders none.

Each member's `meta.pivot` carries every pivot field a response may show for that member:
core's `BelongsToMany::readablePivotFieldsFor($request, $member)`, which leaves out a field that
is hidden or write-only for the request. A field's `hidden(when:)` predicate receives the
related member (the track here, an Eloquent model or whatever the provider returns), so it
can hide a value for one member and show it for another:

```php
Integer::make('position')->hidden(static fn(mixed $track): bool => Accessor::get($track, 'explicit') === true),
```

A field the join row holds no value for renders as `null`, which is what the response pivot
schema in the [OpenAPI document](openapi.md) promises when it lists the field as `required`.
A `Date`, `DateTime` or `Time` pivot field renders in its format (ISO 8601 with an offset for
a `DateTime` by default), and a `DateTime` follows the server's
[`output_timezone`](configuration.md#output_timezone). The pivot model doesn't have to cast
the column: one it leaves uncast is read the way a `datetime` cast would read it, in the
app's timezone.

Each pivot field is also a sort key (`?sort=-position`), and the declared pivot filters apply,
on both the related endpoint and the relationship endpoint. Both page at the relation's
paginator, and both read each member's pivot row in the same query as the page, so `meta.pivot`
costs no extra query. The Eloquent provider resolves the `pivot.` prefix against the join table.
A provider that stores no pivot columns, such as the in-memory one, answers a pivot sort or
filter key with a `400`.

Declare a value constraint on each pivot filter (`->integer()` above). Core types an
unconstrained filter's OpenAPI value from the single column it targets, and `pivot.` means
nothing to it — the prefix is this package's Eloquent convention — so a pivot filter without
one documents as the plain `string` every query parameter is on the wire, not as the integer
or date its column really holds. The constraint buys both a `400` on a mistyped value and
the narrower OpenAPI type.

## Relationship mutations and prohibitions

A relation can prohibit a full-replacement or a removal:

```php
BelongsToMany::make('playlists', 'playlists')->cannotReplace()->countable()->withData();
```

`cannotReplace()` makes a `PATCH …/relationships/playlists` (full replacement) a `403`
(FullReplacementProhibited); `cannotRemove()` makes a `DELETE` a `403`. A `readOnly()`
relation refuses all three the same way, since a relationship mutation is an update of the
parent, and the OpenAPI document lists no mutation operations for it. `readOnly(fn …)` is
judged per request, so the document keeps them. Embedded
belongs-to-many writes in a whole-resource `POST` are applied after the parent is created, since the join row needs the parent's key.

A related id is resolved through the same scope its type's own endpoint reads through: an
Eloquent global scope on the related model (soft deletes included), or a
[provider that scopes its rows](custom-data-providers.md#a-provider-that-scopes-its-rows). An id
that scope hides is a `404`, the same as an id with no row, and a full replacement leaves the
hidden members attached. A related model without global scopes, served by the reference
providers, resolves ids as it always has.

On a `HasMany`/`HasOne` (the foreign key is on the related row, so a row has one parent),
adding a row that another parent holds moves it. That needs the `update` ability on the parent
that holds it now, or on the row itself, or the write is a `403`.
Adding a row that no parent holds needs the `update` ability on the row.
Removing a row the parent doesn't hold changes nothing. Removing one whose foreign key column
is `NOT NULL` is a `403` (`REMOVAL_PROHIBITED`, or `FULL_REPLACEMENT_PROHIBITED` for a
replacement that would drop it), since the row can't be left without a parent.

### Writing a relation through `fillUsing()`

A relation with a `fillUsing()` hook writes its own linkage. The hook runs in place of the
persister's relationship write on every path: an embedded relationship in a `POST` or `PATCH`,
the `…/relationships/{name}` endpoints and atomic operations. The resource's save then persists
what the hook set. A to-one hook receives the `ToOneRelationship`; a to-many hook receives the
`ToManyRelationship` and the endpoint's mode as `$data['mode']`, so it can add, remove or
replace:

```php
MorphTo::make('target', ['boards', 'cards'])
    ->fillUsing(static function (Pin $pin, ToOneRelationship $linkage): Pin {
        $pin->target_kind = $linkage->resourceIdentifier?->type;
        $pin->target_key = $linkage->resourceIdentifier?->id;

        return $pin;
    });
```

The hook gets wire ids, so a type with an id encoder decodes them itself. Validation, the
`cannot*` prohibitions, the policy gates and related-id scoping still run first. A hooked
relation in a `POST` applies before the insert, like an attribute.

## Hidden relations

`hidden()` keeps a relation out of every response. `hidden(fn ($model, $request) => …)`
decides per parent and request:

```php
BelongsTo::make('reviewer', 'users')->hidden(fn ($post) => !Auth::user()?->can('review', $post));
```

When the predicate holds, the relation is left out of the resource, and its related and
relationship `GET`s answer `404` (`RELATIONSHIP_NOT_EXISTS`), the same as an undeclared
relation, so it reads as absent everywhere. The mutation endpoints still find it and keep their
own `403`s.

## Write-only relations

`writeOnly()` makes a relation accept writes and never show up on a read:

```php
BelongsTo::make('approver', 'users')->writeOnly();
```

A client can still set it in a create or update body and through `PATCH`, `POST` and
`DELETE` on `…/relationships/approver`, which answer `204` because the response can't echo
linkage it must not show. It never appears in a response's `relationships`, `?include`,
`?withCount` and `relatedQuery[approver]` are a `400`, and the related and relationship `GET`s
are a `404`. Reads also skip it in storage: a write-only `BelongsTo` is not eager-loaded and a
write-only to-many is never windowed, counted or pivot-queried. The OpenAPI document drops it
from the read schema and lists no read endpoint for it, while the create and update schemas
and the mutation endpoints keep it.

`writeOnly(fn ($request) => …)` decides per request. When the predicate holds, that request
treats the relation as write-only in the same way. The OpenAPI document still describes it
as readable, since it is on other requests.

## Polymorphic relations

- **`MorphTo` (to-one)** — the member's type is resolved from the related object.
  On Eloquent it is a native `morphTo`; the example's `favorites.favoritable` points at a
  track, album, or artist.
- **`MorphToMany` (to-many)** — a mixed collection where each member renders through its own
  per-type serializer. Where the Doctrine reference *throws*, the Eloquent reference **resolves
  it natively** (`morphedByMany` over one polymorphic pivot) — the over-parity headline. The
  example's `libraries.items` mixes tracks + albums + artists:

```php
MorphToMany::make('items', ['tracks', 'albums', 'artists'])
    ->extractUsing(static fn(mixed $library): array => $library->libraryItems());
```

A polymorphic to-many carries no shared filter/sort vocabulary, so `?filter`/`?sort` on its
endpoint are a `400` while `?page` slices.

A `MorphTo` or `MorphToMany` is polymorphic however many types it lists. Declared over one type,
it is still served and documented as polymorphic: the OpenAPI document shows its linkage and
related resource as a one-member union, and its endpoints answer `?filter`, `?sort`, `?page`
and `?withCount` exactly as they would with several types. Adding a second type later
only adds a member to the union. A relation that should take the related type's query
parameters is a `BelongsTo` or `HasMany`.

The documented `include` enum follows the same rule. A single-type `MorphTo::make('pin', ['tags'])`
is listed as `pin`, but no path through it is, so `pin.owner` is not in the parent's enum and
`GET /posts/{id}/pin` documents no `include` parameter, as for a relation over several types.
A request may still send those includes. While there is one type the server knows the
vocabulary, so `?include=pin.owner` and `GET /posts/{id}/pin?include=owner` still work.

### A `MorphTo` stored as a type and id

A parent that stores its target as two plain columns, rather than through an Eloquent
`morphTo`, declares `identifiedBy()` and the relation reads the pair instead of the target:

```php
MorphTo::make('target', ['advertisers', 'agencies'])
    ->identifiedBy(static fn(mixed $campaign): ?array => $campaign instanceof Campaign && $campaign->target_id !== null
        ? [$campaign->target_type, $campaign->target_id]
        : null),
```

The closure returns the JSON:API type and the target's key, or `null` for an empty relation.
A column that stores some other name for the type maps it to the JSON:API type in the closure.
The model needs no relation method for it.

Whether linkage needs a query depends on whether the target's type is guarded on the server.
A type is guarded when something other than a row's existence decides whether a read shows it:
a global scope on its model, a data provider of its own that may scope or hide rows, or a `view`
check that can refuse a row, through a policy, a declared ability or a Gate ability. The answer
is worked out once per server and type and kept.

- An unguarded type's linkage renders from the pair with no query. A list of ten campaigns that
  target only unguarded types is one query, where a native `morphTo` lazy-loads each target to
  learn its type.
- A guarded type's targets load whenever the linkage renders, included or not, in one query
  per guarded type for the whole page through that type's provider, the read
  `GET /advertisers/{id}` makes. A target that read doesn't return, or that the `view` check
  refuses, renders as `null` linkage, the same as any guarded to-one. Unguarded types in the
  same relation keep rendering their pairs.
- `?include=target` loads every type's targets the same way, and a hidden one is left out of
  `included`. Includes below the target, such as `target.owner`, are loaded as usual.
- The relationship endpoint, `GET /campaigns/{id}/relationships/target`, follows the same rule
  as linkage. The related endpoint, `GET /campaigns/{id}/target`, fetches the one target
  through its type's provider and renders `data: null` for an empty pair or a hidden target.

A read permission the relation itself declares is checked against the parent, so it needs no
load: when it refuses, the linkage is `null` whatever the pair says.

A pair naming a type the relation doesn't declare is a `500`, from core's `\LogicException`.
The target renders with the serializer of the pair's type, so its resource needs no
object-aware `getType()`.
Writes are unchanged, so `fillUsing()` owns them, and the OpenAPI document is the same as for
any `MorphTo`. Core's [relations guide](https://github.com/haddowg/json-api/blob/main/docs/relations.md#identifying-the-target-without-loading-it)
covers the declaration.

When a member type is served by its own scoping provider, the members are narrowed per member
type to the rows that provider admits, before the page is cut, so pages stay full and every
count matches. A write naming a member resolves it through its own member type's provider: a
hidden one is a `404`, and a replace keeps the hidden current members attached
([custom data providers](custom-data-providers.md#a-provider-that-scopes-its-rows)).
