# Every rendered to-many is cut to its first page in one bounded query

A plain include read each parent's whole related set, so one include of a large relation was unbounded. Wherever a to-many's linkage renders, included or declared `withData()`, the include batcher now reads its first page through the same `fetchRelatedCollectionBatch()` window the Relationship Queries profile uses, at every level and with batching on or off, so the Eloquent provider runs one `groupLimit` query per relation with the model's global scopes inside it, and in the order a relation method's `orderBy` gives it when the relation declares no default order. Relations reading one association with the same page, order and count share that query, the Relationship Queries window runs first so the walk descends into the pages it cut, and only `withoutPagination()` or a relation exposing no endpoint keeps the whole-set read.

## Consequences

- A `?withCount` total that a counted first page already holds is taken from the page, so naming an included relation in `?withCount` costs no second count.
- A related type with its own provider is asked for the window in its batch, and a parent it leaves out is read with `fetchRelatedCollection()`, as ADR 0044 reads it.
