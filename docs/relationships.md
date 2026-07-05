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
endpoint query it cannot honour is a `400`, never silently ignored
([ADR 0010](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0010-relationship-endpoint-query-params-reject-not-ignore.md)).

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
    ->withFilters(Where::make('position', 'pivot.position'), Where::make('weight', 'pivot.weight'))
    ->paginate(PagePaginator::make()->withDefaultPerPage(2));
```

`position` is required-on-create, `weight` is a second writable pivot field constrained
`weight >= position`, and `addedAt` is server-owned (`readOnly`). Pivot fields are merged into
the payload **before validation**, so cross-field rules see them. (Pivot-meta *read* rendering
landed in Phase 3b —
[ADR 0008](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0008-pivot-meta-read-render-is-deferred-to-phase-3b.md).)

## Relationship mutations and prohibitions

A relation can prohibit a full-replacement or a removal:

```php
BelongsToMany::make('playlists', 'playlists')->cannotReplace()->countable()->withData();
```

`cannotReplace()` makes a `PATCH …/relationships/playlists` (full replacement) a `403`
(FullReplacementProhibited); `cannotRemove()` makes a `DELETE` a `403`. Embedded
belongs-to-many writes in a whole-resource `POST` are applied after the parent is created
([ADR 0009](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0009-embedded-relationship-writes-defer-belongs-to-many-to-after-create.md)).

## Polymorphic relations

- **`MorphTo` (to-one)** — the member's type is resolved from the related object
  ([ADR 0007](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0007-morph-alias-is-decoupled-from-the-json-api-type.md)).
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
