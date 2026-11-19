# Pagination

Collections paginate by default. The strategy is core's — page-number, offset, or cursor
(keyset) — and the package pushes each down through the [Eloquent provider](eloquent.md). This
page covers the Laravel affordances; the paginator DSL and query-param shapes are core's (see
core [pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md)).

## The default page paginator

Out of the box a collection uses the built-in **page-number** paginator, bounded by
`jsonapi.pagination.max_per_page` (a page-size DoS bound — the request stays `200`, an
oversized `page[size]` is clamped, not rejected):

```
GET /api/albums?page[number]=2&page[size]=20
```

Set `max_per_page` to `0` in [configuration](configuration.md) to disable the built-in
default entirely (collections then render unpaginated unless a resource declares its own
paginator).

## Choosing a paginator per resource

A resource picks its paginator by overriding `pagination()` (or, for a relation, `->paginate(...)`).
Core ships:

| Paginator | Query shape | Notes |
| --- | --- | --- |
| `PagePaginator` | `page[number]` / `page[size]` | page-number with a total + last page |
| `OffsetPaginator` | `page[offset]` / `page[limit]` | offset/limit |
| `CursorPaginator` | `page[after]` / `page[before]` / `page[size]` | keyset — stable for large/deep/live collections |

```php
use haddowg\JsonApi\Pagination\PagePaginator;

public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
{
    return PagePaginator::make()->withDefaultPerPage(20);
}
```

## Cursor (keyset) pagination

`CursorPaginator` resolves a keyset window; the provider runs the keyset push-down. It always
appends a deterministic id tie-breaker to the sort, so a page boundary is stable even when the
declared `?sort` column is non-unique (and even for a bare request with no `?sort` — a
primary-key-only keyset). The example's `cursorWidgets` type exercises this against both
providers:

```php
use haddowg\JsonApi\Pagination\CursorPaginator;

public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
{
    return CursorPaginator::make()->withDefaultPerPage(2);
}
```

A cursor page can be ordered by any sortable field and by a key a
[sort family](eloquent.md#sort-families) resolves to a field sort. A custom sort orders a
cursor page only when it implements core's `KeysetSortInterface` and its Eloquent arm
implements `EloquentKeysetSortArmInterface`, which supplies the SQL expression the keyset
orders and compares by; see [custom sorts on a cursor page](eloquent.md#custom-sorts-on-a-cursor-page).
Anything else is a `500` `UnsupportedSort`. The token stores each value under its sort key, so
a cursor minted under `?sort=value.cap` is a `400` `CURSOR_STALE` under `?sort=value.reach`.

## Offering a menu of strategies (`page[kind]`)

A resource can offer **several** pagination strategies and let the client pick one per
request — page-number for a browsing UI, cursor for a stable deep-scroll export. Return a
[`MultiPaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#offering-a-menu-of-strategies)
from `pagination()` (or a relation's `paginate()`); it is itself a `PaginatorInterface`, so
nothing else changes:

```php
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;

public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
{
    return MultiPaginator::make(
        PagePaginator::make()->withDefaultPerPage(20),
        CursorPaginator::make(),
    )->default('cursor');
}
```

The client selects with `page[kind]=page` (or `=cursor`); a strategy-**unique** key selects
without a kind (`page[after]`/`page[before]` → cursor, `page[offset]`/`page[limit]` → offset),
a **shared** key (`page[size]`/`page[number]`) needs `page[kind]`, and an absent `page` uses
the declared `default()`. An unknown kind is a `400 PAGINATION_KIND_UNKNOWN` naming `page[kind]`.
The handler resolves the wrapper to its concrete strategy once, up front, so the count-based and
cursor render paths behave exactly as for a single strategy; the OpenAPI document projects the
menu as one `page` deepObject whose schema is a `oneOf` discriminated by `kind`. A cursor strategy
works on an **included** relation too: an include carries no cursor token, so a cursor-resolved
include renders the **first** cursor page per parent (`next` carries the minted `page[after]`, no
`prev`/`last`), minted through the same per-parent keyset fetch the related endpoint runs — and the
document advertises the cursor-pagination profile even when the primary collection is page-based.

## Included relationships show their first page

Every to-many whose linkage renders, because it is included or declared `withData()`, renders
the **first page** of its members under its effective paginator: the relation's own
`paginate()`, else its related resource's `pagination()`, else the server default. The
relationship object carries the page's links against the relationship endpoint (`first`,
`next` and, when the page is counted, `last`), and `included` holds exactly the members its
linkage lists. With `albums.tracks` paged two at a time, an album of five tracks included with
`?include=tracks` renders its first two, not its whole tracklist:

```json
"tracks": {
  "links": {
    "self": "…/albums/1/relationships/tracks",
    "related": "…/albums/1/tracks",
    "first": "…/albums/1/relationships/tracks?page%5Bnumber%5D=1&page%5Bsize%5D=2",
    "next": "…/albums/1/relationships/tracks?page%5Bnumber%5D=2&page%5Bsize%5D=2"
  },
  "data": [ { "type": "tracks", "id": "1" }, { "type": "tracks", "id": "2" } ]
}
```

The page is the one the related endpoint serves first: in the relation's default order,
narrowed by the filter defaults its endpoint folds in. A page is counted when the paginator
counts (`withCount()`) or the request names the relation in
[`?withCount`](relationships.md#withcount-the-countable-profile); a `countable()` relation then
also carries `meta.total`. A page counted because the request asked for it carries
`withCount=_self_` in its links, so the pages they reach are counted too. A polymorphic
to-many's links address its related endpoint, since its relationship endpoint renders the
whole linkage. Each level of a nested include (`?include=tracks.playlists`) is cut under its
own relation's paginator, and so is the include of a singleton, of a related collection and of
an atomic result. A [`relatedQuery`](relationships.md#the-relationship-queries-profile) sort or
filter reorders or narrows the same first page.

A relation keeps its whole set when it is declared `withoutPagination()`, or when it exposes
neither its relationship nor its related endpoint, which leaves its pages nothing to link to:

```php
HasMany::make('tags', 'tags')->withoutPagination(),
```

The Eloquent provider reads a relation's first pages for the whole page of parents in one
bounded query (see [eloquent](eloquent.md#windowed-relationship-queries--sql-push-down-only)).

Every rendered to-many gets its first page whatever serves its parent. A relation the parent
model can't hold, such as one on a singleton a custom provider serves, declares a
[foreign key](relationships.md#relations-read-through-a-foreign-key), and the related type's
provider cuts every parent's first page from one query.

## Relationship pagination

A related to-many paginates independently — declare it on the relation. Per-parent windowing
uses the SQL push-down described in
[eloquent](eloquent.md#windowed-relationship-queries--sql-push-down-only):

```php
HasMany::make('tracks', 'tracks')->paginate(PagePaginator::make()->withDefaultPerPage(2));
```

The default resolves relation → related resource → server default. The example's `albums.tracks`
and `playlists.tracks`/`orderedTracks` all page two-per-page. The OpenAPI document resolves the
same chain, so the related and relationship endpoints' `page` parameter states the `default` and
`maximum` of the paginator that actually serves them.

A paginator built with `withCount()`, on the resource or on the relation, counts on every
request, so its pages always carry `meta.total` and a `last` link. The OpenAPI
[operation examples](openapi.md#operation-examples) for that collection show them too. A
`MultiPaginator` counts when its default strategy does.

A `CursorPaginator` has no `withCount()`, but a client can still ask for the size of a
`countable()` collection with `?withCount=_self_` under the Countable profile. The handler runs
one `COUNT` over the filtered collection, with its scopes and without the cursor bounds or the
page size, and renders it as the top-level `meta.total` on every page, the related and
relationship endpoints included. A cursor page never carries `meta.page.total` or a `last` link.

The relationship (linkage) endpoint of a to-many, `GET /{type}/{id}/relationships/{rel}`, pages
by default just as its related twin does: a plain request with no query string serves page 1 at
that default size, with `first`/`next` (and `last` when counted) links on the document. A relation
declared `withoutPagination()` still serves its whole linkage. A pivot-carrying `belongsToMany`
pages the same way and accepts the same `?sort`/`?filter` as its related endpoint, pivot columns
included; each identifier on the page keeps its `meta.pivot`, read from the query that fetched
the page. Only a polymorphic to-many is not paged: it has no single paginator to resolve, so it
serves the whole association and rejects `?page`/`?sort`/`?filter` with a `400`.

A relation may declare a `CursorPaginator` too — the related endpoint then serves keyset pages
scoped to the parent, with the cursor links built on the related URL:

```php
HasMany::make('widgets', 'cursorWidgets')->paginate(CursorPaginator::make()->withDefaultSize(2));
```

This covers a pivot-carrying `belongsToMany` as well: the keyset composes on top of the pivot
join (the sort columns always qualify off the related table), and each member's stored pivot
still renders as `meta.pivot` on the cursor page:

```php
BelongsToMany::make('widgets', 'cursorWidgets')
    ->fields(Integer::make('position'))
    ->paginate(CursorPaginator::make()->withDefaultSize(2));
```

### Cursor pages on the relationship (linkage) endpoint

A **queried** relationship GET (`GET /{type}/{id}/relationships/{rel}` with
`?page`/`?sort`/`?filter`) over a cursor-paginated relation windows the identifier linkage to
a keyset page: the document's pagination links carry the real `page[after]`/`page[before]`
cursors (never a `last`), while the body stays links-only — identifier `data` members and no
`meta.page` (see [core's responses](https://github.com/haddowg/json-api/blob/main/docs/responses.md)). A bare relationship GET serves the first keyset page at the
relation's default size. A pivot relation's cursor page filters on pivot columns but sorts only on
the related resource's own sorts, because the keyset reads its columns off the related table.

### Advertising the cursor-pagination profile

A `CursorBasedPage` carries the published
[cursor-pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/),
but core only advertises a page profile the server has **registered**. Core's
`CursorPaginationProfile` is in the default `jsonapi.profiles` list, so every cursor page
(primary, related, and windowed linkage) advertises the profile URI in `jsonapi.profile` and
the `Content-Type` `profile` media-type parameter out of the box. A published config that
trims it from the list stops that:

```php
// config/jsonapi.php
'profiles' => [
    \haddowg\JsonApi\Pagination\CursorPaginationProfile::class,   // keep this one for cursor pages
    \haddowg\JsonApi\Schema\Profile\CountableProfile::class,
    \haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile::class,
    \haddowg\JsonApi\Schema\Profile\FieldSelectionProfile::class,
],
```

See [configuration](configuration.md#profiles).

## Pagination links and meta

The paginator emits the standard `links.first`/`prev`/`next`/`last` (as the strategy allows —
a cursor emits `prev`/`next` only) and `meta.page` totals. These are core's rendering; nothing
Laravel-specific to configure.
