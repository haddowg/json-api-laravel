# Data access goes through the ported Provider/Persister SPI, with Eloquent as the reference implementation

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** The core is storage-agnostic — it owns metadata and execution seams
(`FilterHandlerInterface` / `SortHandlerInterface`, the hydrators, the response VOs)
but no data layer. The Symfony bundle already owns a proven design for that layer: a
`DataProvider` read SPI + a `DataPersister` write SPI, resolved per resource type,
with Doctrine as the reference implementation and an in-memory pair as the test
double and conformance witness (bundle ADR 0004). This package needs a data layer
for Eloquent, and can either reuse that SPI shape or invent an Eloquent-native one.

**Decision.** **Port the bundle's Provider/Persister SPI**, re-namespaced to
`haddowg\JsonApiLaravel\`, rather than design a new data layer. `DataProviderInterface`
(`supports` / `fetchOne` / `fetchCollection` / `fetchRelatedCollection(Batch)` /
`countRelated` / `relatedToOneMatches(Batch)` / `fetchRelationshipPivot`) and
`DataPersisterInterface` (`supports` / `instantiate` / `create` / `update` / `delete`
/ `mutateRelationship`, with a segregated `TransactionalDataPersisterInterface`) are
resolved through a **per-type first-match registry ordered by descending priority**;
the **Eloquent reference pair registers at priority `-128`** so any application
provider (default priority `0`) shadows it for the types it claims, with zero
configuration. An **in-memory provider / persister / store** is ported as a test
double and the conformance witness that runs alongside Eloquent on every phase.

**Considered and rejected: an Eloquent-native model, no SPI.** A resource declares
`$model = Album::class` and the engine talks to Eloquent directly — the smallest
possible build and the most "Laravel-native" surface. **Rejected** because it welds
the generic CRUD engine to Eloquent (excluding every other store and any test
without a database), collapses the dual-provider conformance witness that keeps a
finding attributable to a core seam versus the data mapping, and diverges from both
the bundle's shape and the core's own metadata-versus-execution split — which would
undermine the byte-compatible-OpenAPI parity obligation of the second-witness posture
(ADR 0001). The SPI's "Laravel-native" surface is delivered *on top of* the interface
(Eloquent relation internals implement the batch seams, `DB::transaction` wraps the
transactional one), not by discarding it.

**Consequences.** More interfaces than a direct-Eloquent tool, accepted as the price
of a platform rather than an ORM-only shim. The registry, the SPI, and the in-memory
witness are ported in Phase 0 (the interfaces and registry complete; the in-memory
read path slim) so the Eloquent implementation and writes slot into a stable contract
in Phases 1–2. Symfony's tagged-iterator resolution becomes Laravel container tagging
(a priority-sorted, tagged binding); the *semantics* — first `supports()` wins,
`-128` fallback — are identical to the bundle (bundle ADR 0007).

**Deferred: attribute-driven auto-registration of the reference pair (the "zero
configuration" promise).** In the bundle, `#[AsJsonApiResource(entity: …)]` plus a
compile-time `DoctrineEntityMapPass` builds the `type → entity` map and auto-registers
the reference provider at `-128`, so an app writes no wiring. Phase 1 ships the
`EloquentDataProvider` and its `type → model` map, but that map is still constructed
**by hand** (`new EloquentDataProvider([...])`, as the workbench does) — the Laravel
twin of `DoctrineEntityMapPass` (a `model:` param on `#[AsJsonApiResource]`, recorded by
discovery, accumulated into one `-128` provider in `JsonApiServiceProvider::boot()`,
with the duplicate-type/different-model guard) lands in **Phase 2**, alongside the
persister half that completes the "reference pair". Until then the `-128` priority and
first-`supports()` semantics are faithful — an application provider at the default `0`
still shadows a hand-wired reference provider — but the *zero-config* headline is not
yet delivered. Tracked for the Phase 5 parity audit.
