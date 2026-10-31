# `?include` batching orchestrates through the SPI, not top-level Eloquent `with()`

Handing the `?include` tree to `Model::with()` is the obvious Laravel route, but it cannot return a per-parent page, total or `hasMore` for the Relationship Queries profile and has no in-memory equivalent, so a provider-agnostic batcher drives one `fetchRelatedCollectionBatch()` per relation level and the Eloquent provider implements it with relation eager-load internals. A batch holds an entry for every parent it loaded, and a parent missing from it is read per parent, never assumed empty. Batched values reach core as out-of-band linkage for that request; only an Eloquent model also takes them with `setRelation()`, because the load-state seam reads `relationLoaded()` and the model's own lazy load would make the same write.

## Consequences

- Nothing a read loads is written onto a non-Eloquent parent, so a shared in-memory record is never changed by a read.
- Eager owner-side to-ones (`BelongsTo`, `MorphTo`) are preloaded even without `?include`, since core always renders their linkage and would otherwise lazy-load once per parent.
