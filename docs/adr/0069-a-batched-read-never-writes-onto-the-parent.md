# A batched read never writes onto the parent

A provider that didn't override `fetchRelatedCollectionBatch()` returned an empty batch, and the include batcher took every parent missing from it as having nothing related. It wrote `null` or an empty set onto each parent, through the eager linkage preload too, with no `?include` at all. On the in-memory store that parent is a record every request shares, so one read emptied the relation for every later request. A batch now holds an entry for every parent it loaded, and a parent with no entry wasn't loaded. The include batcher renders that parent's own relation, and the Relationship Queries window and `?withCount` read it with `fetchRelatedCollection()`. The reference providers already gave every parent an entry, so their queries don't change.

Nothing the batchers load is written onto an object that isn't an Eloquent model. The include batcher hands its value to core as linkage for that request, the way the window and scoped-provider loads already did, and a to-one that a `relatedQuery` filter excludes renders as none through core's to-one linkage seam instead of being nulled on the parent. An Eloquent model still takes the batched value with `setRelation()`, because the load-state seam reads `relationLoaded()` and the model's own lazy load would make the same write.

## Consequences

- A provider that leaves out a parent with nothing related gets a per-parent read for that parent. The output is the same, with more queries.
- The window batcher no longer snapshots and restores each in-memory parent's column before its batch. That only protected against writes the batchers no longer make.
