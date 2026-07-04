# The `meta.pivot` READ render hook is deferred to Phase 3b (the seam ships in 3a)

- **Status:** accepted — **superseded by Phase 3b: the render hook is now wired** (see the Phase-3b note below)
- **Date:** 2026-07-04

**Context.** For a `belongsToMany` relation with declared pivot fields, the bundle renders the
stored pivot values as `meta.pivot` under each linkage identifier on the related/relationship
endpoints. This branch builds and unit-tests the *provider* seam that reads that data
(`EloquentDataProvider::fetchRelationshipPivot()` off Eloquent's pivot accessor; the in-memory
witness returns `[]`, the documented in-memory pivot boundary), but does **not** wire a render
hook that emits `meta.pivot` on any document this phase. This is a deliberate 3a→3b
re-classification: the Phase-3a task scope covers relationship *reads* and explicitly holds
*pivot writes* (and the Relationship Queries profile) for Phase 3b with the pivot machinery,
and the `meta.pivot` render requires decorating the linkage serializer with the fetched pivot
map — serializer surface that lives naturally alongside the pivot-write / merge-before-validate
work rather than ahead of it. It is a divergence from the blueprint's line that scopes pivot
READ to 3a, recorded here so it is a decision with a paper trail rather than a silent omission.

**Consequences.**

- **The seam is ready, not dead.** `fetchRelationshipPivot()` is implemented and directly
  tested (it reads each member's declared pivot fields keyed by related id), so Phase 3b wires
  a render hook onto an already-refereed provider method rather than building both at once.
- **No `meta.pivot` renders on any 3a document.** `GET /{type}/{id}/tags` and its relationship
  endpoint render linkage identifiers with no `meta.pivot`; a resource's declared pivot fields
  are inert on reads until 3b.
- **Confirm on the 3a→3b boundary.** This defers a capability the blueprint placed in 3a; it
  should be confirmed with the plan owner when the phase-3a work is reviewed, and un-deferred by
  wiring the render hook (a `PivotMetaSerializer` twin + a dual-provider — Eloquent-only —
  conformance assertion) in 3b.

**Phase 3b update (render hook wired).** The deferral above is now lifted. The
`PivotMetaSerializer` twin is ported (`src/Serializer/PivotMetaSerializer.php`), and a
parent-serializer decorator chain (`PivotParentSerializer` + `AbstractPivotParentSerializer` +
`RebindsPivotLinkage` + `PivotSubstitutingResolver`) rebinds the pivot relation's linkage on
the relationship-linkage endpoint. `CrudOperationHandler` now wires all three renders fed by
`fetchRelationshipPivot()`: the **related** endpoint (`GET /{type}/{id}/{rel}`) wraps the
related serializer, the **relationship** endpoint (`GET …/relationships/{rel}`) and the **200
linkage echo** of a pivot mutation wrap the parent serializer. Because the in-memory provider
returns `[]` (the documented pivot boundary), the wrap is a no-op there — so `meta.pivot`
renders **Eloquent-only**, asserted dual-provider (Eloquent-present / in-memory-absent) by
`tests/Feature/{Eloquent,InMemory}PivotTest.php`. The primary-document pivot linkage (bundle
ADR 0102, a batched `fetchRelatedPivotMapBatch`) is the one deliberately-remaining tail, left
to a later slice.

**Over-parity note (mutation echo).** The Symfony bundle's `mutateRelationship` echo renders
through the PLAIN parent serializer — it does NOT wrap the 200 linkage echo with the pivot
map (the bundle wraps only the windowed pivot relationship endpoint + primary documents). The
Laravel echo here DOES wrap it (via `relationshipLinkageSerializer`), so a pivot mutation's
`200` response carries `meta.pivot` where the bundle's does not — a deliberate over-parity
divergence (the echoed linkage is internally consistent with the relationship-READ endpoint,
which the bundle also wraps). Recorded so the wire difference is a decision, not a silent
byte-drift; dropping the echo wrap would restore byte parity if strict twin-equality is later
required.
