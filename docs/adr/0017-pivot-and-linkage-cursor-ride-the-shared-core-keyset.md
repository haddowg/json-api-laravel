# Pivot-related and linkage-GET cursor pagination ride the shared core keyset and the withPage seam

- **Status:** accepted

This closes the two related-read cursor gaps ADR 0016 recorded as deliberately out, and it
does so with NO new execution machinery. First, the package's local copies of the keyset
classes (`KeysetResolver`/`KeysetColumn`/`CursorTokenMinter`/`InMemoryKeyset`) were deleted
in favour of core's hoisted `haddowg\JsonApi\Collection\Keyset\*` (core ADR 0123, the
shared follow-up with the bundle) — the providers now pass the sort inputs
(`requestedSort`/`sorts`/`defaultSort`) to core's `KeysetResolver::resolve()` directly, and
core owns the resolver/round-trip unit contract; only the store-specific `EloquentKeyset`
WHERE/ORDER builder stays local.

**Pivot related** (`GET /{type}/{id}/{rel}` over a pivot-carrying `belongsToMany`) needed
no provider change at all: the relation query is already scoped by the pivot INNER JOIN and
`EloquentKeyset` qualifies every keyset column off the RELATED model's table, so the cursor
page composes under the handler's existing `meta.pivot` wrap (the `PivotMetaSerializer`
wrap is applied before the cursor branch renders). The dual-provider referee seeds the SAME
pivot map on both sides — the Eloquent half off the join table, the in-memory half through
a workbench provider that serves `fetchRelationshipPivot()` off the fixture POPO (the
built-in witness's empty-pivot boundary, ADR 0008, is unchanged).

**Linkage GET** (`GET /{type}/{id}/relationships/{rel}` with a relation-declared
`CursorPaginator`): the windowed-relationship supply path now narrows a
`CursorCollectionResult` into `CursorPaginator::fromBoundaries()` (the real minted tokens,
never the degraded token-less interface-conformance page) and the handler attaches the
built page to the response via core's NEW `IdentifierResponse::withPage()` (core ADR 0124)
— the body stays links-only (no `meta.page`; the `page[after]`/`page[before]` links ride
the relationship-pagination seam) while the page's profile is advertised exactly as
`RelatedResponse::fromPage` does. Queried PIVOT linkage still 400s (docs/adr/0010 —
unchanged boundary).

Advertising the published cursor-pagination profile requires registering it on the server
(core drops a page profile the server has not registered); the package deliberately does
NOT register it by default — existing responses' `Content-Type` stays byte-stable — and
instead exposes the new `jsonapi.profiles` config (class-strings appended to the built-in
Countable + Relationship Queries registrations).
