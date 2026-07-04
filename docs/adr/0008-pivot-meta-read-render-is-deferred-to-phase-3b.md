# The `meta.pivot` READ render hook is deferred to Phase 3b (the seam ships in 3a)

- **Status:** accepted
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
