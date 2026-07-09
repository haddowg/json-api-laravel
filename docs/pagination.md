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
menu as one `page` deepObject whose schema is a `oneOf` discriminated by `kind`. A cursor-resolved
**included** relation remains subject to the [push-down-only windowing](#relationship-pagination)
limitation, so a menu that includes a page strategy is the safe choice on an includable relation.

## Relationship pagination

A related to-many paginates independently — declare it on the relation. Per-parent windowing
uses the SQL push-down described in
[eloquent](eloquent.md#windowed-relationship-queries--sql-push-down-only):

```php
HasMany::make('tracks', 'tracks')->paginate(PagePaginator::make()->withDefaultPerPage(2));
```

The default resolves relation → related resource → server default. The example's `albums.tracks`
and `playlists.tracks`/`orderedTracks` all page two-per-page.

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
`meta.page` (core ADR 0124). A bare (unqueried) relationship GET still renders the whole
association. A queried **pivot** relationship GET remains a `400` — query parameters on a
relationship endpoint are rejected, not silently ignored.

### Advertising the cursor-pagination profile

A `CursorBasedPage` carries the published
[cursor-pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/),
but core only advertises a page profile the server has **registered**. Register it via the
`jsonapi.profiles` config (class-strings, resolved through the container) and every cursor
page — primary, related, and windowed linkage — advertises the profile URI in
`jsonapi.profile` and the `Content-Type` `profile` media-type parameter:

```php
// config/jsonapi.php
'profiles' => [\haddowg\JsonApi\Pagination\CursorPaginationProfile::class],
```

## Pagination links and meta

The paginator emits the standard `links.first`/`prev`/`next`/`last` (as the strategy allows —
a cursor emits `prev`/`next` only) and `meta.page` totals. These are core's rendering; nothing
Laravel-specific to configure.
