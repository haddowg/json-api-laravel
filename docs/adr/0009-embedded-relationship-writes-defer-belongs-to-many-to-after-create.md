# Embedded relationship writes apply through the persister seam, deferring belongsToMany to after create

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** A whole-resource write may embed associations in `data.relationships` (Phase 3b).
Core's per-type hydrator, left to run over such a body, assigns the scalar linkage id to the
typed association property — a `TypeError` on a POPO / a NOT-NULL `QueryException` on Eloquent
(a 500). The Symfony bundle solves this by stripping `data.relationships` before core hydrates
and setting the associations through the `DataPersister::mutateRelationship()` seam
(`flush: false`, the subsequent `create()`/`update()` owning the single commit) — bundle ADR
0018. The Laravel handler's `create()`/`update()` did not yet do this, so an embedded
relationship write 500'd. The bundle applies every embedded relationship uniformly BEFORE the
create (its `on()`-flattened-attribute case needs that order); the Eloquent reference cannot
follow suit for a `belongsToMany`, whose join-table insert needs the parent's primary key,
which a not-yet-persisted create target does not have.

**Decision.** The handler ports the bundle's `extractRelationships` / `applyRelationships` /
`withoutRelationships` / `existingPivots` pass, with one Eloquent-forced divergence in the
create ordering:

- **UPDATE:** the parent is already keyed, so every embedded relationship applies uniformly
  under the outer write transaction (`flush: false`), each gated by the relation's request-aware
  `cannotReplace`/`cannotAdd`/`cannotRemove` flags in `Mode::Replace` (the same `403` the
  dedicated `…/relationships/{rel}` endpoint raises). The merge-before-validate pass folds the
  loaded parent's stored pivot rows in.
- **CREATE:** a to-one (owner-side FK — `BelongsTo`/`MorphTo`) applies inline before the create,
  the FK set on the parent instance and committed by the create's own insert; a to-many (join /
  inverse FK — `belongsToMany`/`hasMany`) is **deferred to after `create()` returns the keyed
  parent**, so the join insert has a parent key. The `cannot*` gate is skipped on a create (a
  create sets the initial state — there is nothing to replace, and a `cannotReplace` relation,
  which has no relationship endpoint, would otherwise be unsettable).

The reference `EloquentDataPersister::mutateBelongsTo()` is corrected to honour `flush: false`:
it sets the owner FK on the parent instance but does **not** `save()` it, so an embedded to-one
on a create target (whose other NOT-NULL columns are still un-hydrated) is never inserted
mid-association — the subsequent `create()` owns the single insert.

**Consequences.**

- Embedded relationship writes work on both providers (the in-memory witness sets the plain
  association list; the Eloquent reference `associate`s / `sync`s), refereed by
  `RelationshipWriteConformanceTestCase` and the un-skipped `WriteConformanceTestCase`
  relationship arms.
- The create ordering is a recorded divergence from the bundle's uniform pre-create apply,
  forced by Eloquent's PK-before-join requirement — not a behavioural difference on the wire.
- Embedded linkage is validated with the same pass the endpoint uses (a `409` resource-type
  conflict / `422` malformed linkage or pivot meta), so a bad embedded linkage never reaches
  storage; a `lid` in an embedded linkage stays core's `400 LOCAL_ID_NOT_SUPPORTED`.

## Addendum (Phase 3b hardening)

Four corrections to the shape above, all refereed dual-provider:

- **Partition by STORAGE SIDE, not cardinality.** The create pre-vs-post split is by which side
  owns the foreign key, not to-one-vs-to-many: only an owner-side FK — a `BelongsTo` (but NOT its
  `HasOne` subclass, which core models as an inverse-FK to-one) or a `MorphTo` — applies inline
  before `create()`; every `HasOne`/`HasMany`/`BelongsToMany`/`MorphToMany` defers. Splitting a
  `HasOne` by cardinality would land it in the pre-create bucket and `save()` the related row with
  its FK set to the not-yet-keyed parent's NULL key — silently dropping the association (or a
  NOT-NULL FK 500). `CrudOperationHandler::appliesBeforeCreate()` encodes this. The reference
  persister's `orphan()` also nulls a `MorphOneOrMany`'s `*_type` discriminator alongside the FK.
- **Create is atomic.** `create()` + the deferred apply now run inside ONE
  `writeTransactionally()` boundary: `create()`'s own transaction nests as a savepoint, so a
  failing deferred join insert rolls the parent row back too — no orphaned, partially-related
  resource. This restores the bundle's single-flush atomicity despite the reordering (the earlier
  shape committed the parent, then applied the deferred embeds each in its own transaction — a
  500 could leave the parent durably committed).
- **Embedded pointers locate the relationship.** An embedded linkage violation points at
  `/data/relationships/<rel>/data[/<n>]/{type,id,meta/pivot/<field>}` — NOT the
  relationship-endpoint `/data/…` shape (which in a whole-resource body would collide with the
  resource's own `data.type`). `ResourceValidator::validateRelationshipLinkage()` takes an
  `$embeddedRelationName` that selects the pointer family; the relationship endpoints pass `null`.
- **Linkage id-format is validated.** Each linkage id (embedded and endpoint) is checked against
  the related type's declared `Id` format (`422` at the linkage id), ported from the bundle's
  `endpointLinkageError`/whole-resource id pass and resolved by the member's OWN type (polymorphic
  safe) via a `resolveResource` closure the handler threads from the server.

**Residual (recorded, not fixed): a nonexistent linkage id.** Neither provider validates member
EXISTENCE (parity with the bundle, which relies on DB constraints). The reference persister builds
a keyed blank and `associate()`s / `sync()`s it, so a nonexistent id persists a dangling FK where
the schema enforces none (or 500s a real FK); the in-memory witness resolves the id to `null` and
clears/drops the member. This is a deliberate pass-through-to-storage-constraints stance — the
workbench now declares FK constraints on the pivot joins (migration 0004), but SQLite's default
`foreign_key_constraints=false` leaves them unenforced in CI. A shared read-provider existence
check (a `404`) on both providers is the follow-up if the divergence is judged material.
