# Client-selectable pagination carries a page schema and resolves the paginator up front

- **Status:** accepted

Core added a `MultiPaginator` (a `PaginatorInterface` composing several strategies a
client selects per request with `page[kind]`), and with it moved the OpenAPI
`page[…]` projection onto the paginator itself: `PaginatorInterface` now exposes
`kind()`, `describePageSchema()` and `resolve()`, and the projector emits the whole
`page` family as one `deepObject` parameter carrying that schema (a `oneOf` menu for
a `MultiPaginator`). The Eloquent integration mirrors the Symfony bundle (its ADR
0116) exactly — the two must project byte-identically:

- **Metadata** — `MetadataSource` no longer discriminates a `PaginatorKind`; the
  retired `PaginatorKindResolver` is deleted and `TypeMetadata`/`RelationMetadata`
  now carry the resolved paginator's `describePageSchema()` (`null` when
  unpaginated). The projector reads that schema directly, so a custom paginator —
  or a menu — documents its real `page[…]` keys with no integration-side switch.
- **Handler** — `CrudOperationHandler` calls `$paginator->resolve($request)` once,
  up front, at each of its three paginator-binding sites (primary collection, the
  related collection, and the relationship-linkage collection), **before** every
  `instanceof CursorPaginator` render/count branch. A single strategy resolves to
  itself (unchanged behaviour, proven by the whole suite); a `MultiPaginator`
  resolves to the concrete child the request selects.

**Why.** Selection is a core concern (the discriminator/unique-key/default rules
live in `MultiPaginator::resolve()`), so the integration's only job is to feed the
page schema into the metadata and resolve the wrapper before the render branches.
The dual-provider `CursorConformanceTestCase` witnesses it: `cursorWidgets` now
offers a page+cursor menu (default cursor), so the existing keyset suite proves the
menu is transparent while new cases prove `page[kind]=page` and the page-unique
`page[number]` select the count-based strategy, a shared `page[size]` falls back to
the cursor default, and an unknown kind is a `400`. `composer byte-compat` stays
green — both integrations project the same core-described `page` schema.

**Cursor on a batched include (ADR 0006 lifted).** A cursor-resolved **included**
relation now renders a first cursor page per parent rather than throwing. An include
carries no cursor token (the Relationship Queries profile pins the included page to
page 1), so the window is a **boundaryless** `CursorWindow` — a first page is just
the first N rows under the keyset sort + id tiebreak, which is what the parent-scoped
`fetchRelatedCollection` keyset path already computes. So `EloquentDataProvider::
fetchWindowedBatch()` routes a `CursorWindow` to a per-parent loop over that same
keyset fetch (each parent's forward cursor minted from its boundary row via the shared
`CursorTokenMinter`) instead of throwing — a real per-parent keyset `LIMIT` push-down,
not the PHP window ADR 0006 forbids. `RelationshipWindowBatcher::paginationFor`
renders a `CursorBasedPage` through `CursorPaginator::fromBoundaries` (`next` carries
the minted `page[after]`, `prev`/`last` omitted). The in-memory witness already
windowed each parent through that same path, so the two providers are byte-identical
(`CursorIncludeConformanceTestCase`). The batcher surfaces each cursor page's profile
(`WindowedRelationshipPagination::activatedProfiles()`) and the handler advertises it
on the document via core's `withActivatedProfiles()`, so a cursor include advertises
the cursor-pagination profile even when the primary collection is page-based (witnessed
against a registered profile). This reverses the follow-up ADR 0006 earmarked — no new
parent-partitioned keyset push-down was needed, only reusing the per-parent keyset path.
