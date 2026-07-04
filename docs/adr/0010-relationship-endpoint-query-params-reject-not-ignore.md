# A relationship-endpoint query it cannot honour is a 400, never silently ignored

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** `GET /{type}/{id}/relationships/{rel}` (the linkage endpoint) may carry
`?page`/`?sort`/`?filter`/`?withCount=_self_`. A **monomorphic, non-pivot to-many** linkage
endpoint windows them (page 1 of the request's sort/filter, supplied out-of-band — the same
machinery `fetchRelated` uses). The other relation shapes cannot be windowed through that path:

- a **to-one** has no collection to sort/page/count;
- a **polymorphic to-many** has no single related provider or shared sort/filter vocabulary;
- a **pivot `belongsToMany`** would need a windowed pivot linkage carrying `meta.pivot` (the
  bundle's ADR 0096 — a `PivotParentSerializer` over the windowed pivot map), which this package
  has not yet built.

The 3a code excluded all three from the windowing gate and then fell through to rendering the
FULL association — **silently ignoring** the client's `?sort`/`?filter`/`?page`. A client
believing its sort/filter applied and getting the unfiltered full set is the worst failure mode
(worse than an error), and it diverges from the Symfony bundle, which either windows (pivot) or
`400`s (polymorphic/to-one) these.

**Decision.** When the linkage endpoint is addressed with a query parameter it cannot honour on
the addressed relation shape, it returns a **`400`** rather than rendering the full set:

- `?withCount=_self_` → `RELATIONSHIP_COUNT_NOT_ALLOWED` (the related endpoint's same gate);
- a `?filter` → `FILTER_PARAM_UNRECOGNIZED` (mirroring `fetchRelated`'s polymorphic-to-one arm);
- a `?sort`/`?page` → `QUERY_PARAM_UNRECOGNIZED` naming the offending parameter.

A **plain** relationship read (no query parameters) still renders the whole association off the
loaded parent — the Phase-3a full-linkage contract, preserved. `CrudOperationHandler`'s
`rejectRelationshipEndpointQuery()` encodes the rejection; the reject is refereed dual-provider
(a queried pivot endpoint and a queried to-one endpoint both `400`).

**Deliberately deferred (confirm with the plan owner), each a `400` for now:**

- the **windowed pivot relationship endpoint** (the pivot page + its `meta.pivot`) — the same
  deferred tail ADR 0008 records for the primary-document pivot linkage;
- **to-one linkage nulling on the *relationship* endpoint** under a `?filter` (the related
  endpoint `GET /{type}/{id}/{rel}` already nulls a filter-excluded to-one, ADR 0068; the
  linkage-endpoint twin nulls out-of-band and is deferred with the pivot work);
- **polymorphic to-many windowing** on the linkage endpoint.

**Consequences.** No relationship-endpoint query parameter is silently dropped. The deferred
surfaces are honest `400`s a client can see, not silent full-set renders; lifting each is
additive (a `400` becomes a windowed/nulled `200`).
