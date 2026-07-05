# Cursor (keyset) pagination on related collections reuses the primary keyset, scoped by the relation query

- **Status:** accepted

A relation-declared `CursorPaginator` (`HasMany::make(…)->paginate(CursorPaginator …)`) now
executes end to end on `GET /{type}/{id}/{rel}`. We did NOT build a separate
cursor-over-relation capability: each provider's `fetchRelatedCollection()` simply narrows a
`CursorWindow` into the SAME private `runCursor()` its primary `fetchCollection()` already
runs — on Eloquent the relation query is already scoped to the parent (the FK/pivot
constraint), so the keyset WHERE + the forced NULL=largest ORDER BY compose on top of it; on
the in-memory witness the keyset runs over the member set read off the parent. The handler's
`fetchRelated()` to-many tail mirrors the primary collection's `CursorCollectionResult`
narrow into `CursorPaginator::fromBoundaries()` + `RelatedResponse::fromPage()`, so core
renders the cursor links scoped to the related URL, no `last` link, and
`meta.page{perPage,from,to,hasMore}` — nothing new to render, and the two providers stay
refereed byte-for-byte over the shared `cursorGroups → cursorWidgets` conformance partition
(the bundle's Doctrine provider mirrors the same seam, bundle ADR 0063).

Deliberately still out: the pivot related path (`fetchRelatedPivotCollection`), windowing a
cursor relation's relationship-linkage GET (a queried linkage renders a degraded token-less
page via the paginator's count-free interface conformance), and the polymorphic PHP-window
path (no single related keyset vocabulary). Hoisting the byte-identical keyset machinery
(`KeysetResolver`/`KeysetColumn`/`CursorTokenMinter`/`InMemoryKeyset`) into core is a shared
follow-up with the bundle.
