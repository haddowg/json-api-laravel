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

## Pagination links and meta

The paginator emits the standard `links.first`/`prev`/`next`/`last` (as the strategy allows —
a cursor emits `prev`/`next` only) and `meta.page` totals. These are core's rendering; nothing
Laravel-specific to configure.
