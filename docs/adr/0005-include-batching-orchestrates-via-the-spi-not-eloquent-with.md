# `?include` batching orchestrates through the SPI, not top-level Eloquent `with()`

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** PLAN decision 8 requires the provider-agnostic
`RelatedIncludeBatcher`/`RelationCountBatcher` to orchestrate compound-document loading, with
the Eloquent provider *implementing* the batch methods using relation eager-load internals
(`addEagerConstraints($parents)` + `getEager()` + dictionary `match()` + `setRelation()`
write-back). The obvious Laravel shortcut — hand the whole `?include` tree to
`Model::with(...)` and let Eloquent eager-load it — was **rejected** as the orchestrator: a
top-level `with()` cannot return the per-parent *page* (windowed to-many) or per-parent
*totals*/`hasMore` the Relationship Queries profile needs, and it diverges from the in-memory
witness (which has no `with()`), breaking the dual-provider referee. So the batcher drives one
SPI `fetchRelatedCollectionBatch()` call per relation-level and writes each parent's result
back with `setRelation()`. That write-back is *also* the load-state mechanism: it makes
`Model::relationLoaded()` true, which `EloquentRelationshipLoadState` reports, so a preloaded
lazy relation renders without a re-fetch — the include batcher and the load-state seam are the
same mechanism read from both ends.

**Consequences.**

- **The load-state seam is a consequence of the write-back, not a parallel path.** There is no
  separate "mark loaded" step; `setRelation()` is the single source of truth.
- **Eager owner-side to-ones are preloaded even when not `?include`d.** Core never consults the
  load-state seam for an eager relation (`BelongsTo`/`MorphTo` always emit linkage `data`), so
  without a preload an Eloquent collection would lazy-load that to-one once per parent — the
  exact N+1 this decision set out to eliminate ("preload via `setRelation` BEFORE any
  `readValue`"). The batcher therefore batch-loads eager monomorphic to-ones through the same
  fast path (`RelatedIncludeBatcher::preloadEagerLinkage()`), no recursion, Eloquent-only (a
  POPO already holds the value, so the in-memory witness stays byte-identical). Batching remains
  a pure optimization: the `disable()`/`enable()` witness seam proves the rendered document is
  identical with and without it, only the query profile changes (see the Eloquent conformance
  suite's byte-identity + query-count test).
- **The bundle's `on()` eager-load pass is not ported yet.** It is inert without `on()`
  declarations (none exist in this package), so it is a documented seam on the batcher rather
  than dead code; a Phase-4 `on()` port must restore the per-level already-loaded guard the
  bundle's pass carries.
