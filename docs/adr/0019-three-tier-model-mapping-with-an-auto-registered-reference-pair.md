# Resource→model mapping resolves in three tiers, feeding an auto-registered reference Eloquent pair

- **Status:** accepted
- **Date:** 2026-07-05

**Context.** ADR 0002 ported the Provider/Persister SPI with the reference Eloquent pair
registered by hand (`new EloquentDataProvider($modelByType)` at `-128`) and explicitly
deferred the bundle's zero-config affordance: `#[AsJsonApiResource(entity: …)]` read by a
compile-time `DoctrineEntityMapPass` that builds the `type → entity` map and injects it
into the reference Doctrine provider, so a bundle application writes no data wiring at
all. Until now this package had neither the attribute nor any convention, so the
getting-started promise ("the package maps `albums` to your `Album` model by convention")
was not true.

**Decision.** A type resolves to its Eloquent model through **three tiers, first match
wins per type**:

1. an **explicit registration** — `JsonApi::provider()`/`persister()` wiring, unchanged.
   It shadows everything because it sits above the auto pair in the registries'
   priority order, not because the map resolver knows about it;
2. the **declared model** — `#[AsJsonApiResource(model: Album::class)]`, the bundle
   `entity:` twin. The `DiscoveryScanner` guards the class-string at scan time (must
   exist and extend `Illuminate\Database\Eloquent\Model` — the Laravel twin of the
   compiler pass's missing-entity guard) and carries it on the `ResourceDescriptor`,
   so it survives the `jsonapi:optimize` snapshot exactly like the ADR 0015
   serializer/hydrator overrides. One type declared against two different models throws
   (the pass's duplicate guard); two types sharing one model is legal — that IS the
   "two types, one model" pattern, which convention can never guess;
3. the **convention guess** — the kebab/plural type studly-singularized under the
   configurable `jsonapi.eloquent.model_namespace` (default `App\Models`; `albums` →
   `App\Models\Album`), claimed only when that class exists and is an Eloquent model.
   No match, no claim. Null namespace disables the tier.

Tiers 2+3 are resolved by the `Eloquent\ModelMapResolver` off the memoized discovery
descriptors, and — when the map is non-empty — the service provider appends ONE
reference `EloquentDataProvider`/`EloquentDataPersister` pair over that map to the
registries at priority **`-256`**, below the documented explicit-wiring floor of `-128`,
so any hand registration (an application provider at the default `0`, or the reference
pair wired by hand per the docs) shadows the auto pair per type. The auto pair
`supports()` only the mapped types: an unresolvable type raises the same no-provider
`LogicException` it always did. Construction is lazy (inside the registry singletons,
resolved on first use, after discovery — snapshot-loaded or live-scanned alike), so
`route:cache`/`jsonapi:optimize` timing is untouched; the auto provider composes with
the `IdEncoderResolver` (ADR 0014) and picks up application filter/sort arms through the
`jsonapi.eloquent.filter_arm`/`jsonapi.eloquent.sort_arm` container tags.

**Why this is NOT the rejected "resource declares `$model`, no SPI" design** (ADR 0002,
"Considered and rejected"). That rejection welded the CRUD engine to Eloquent by making
the resource's model declaration the engine's direct data access. Here the declaration
(or guess) merely *builds the map injected into the same SPI reference pair* — exactly
how the bundle's `entity:` feeds its Doctrine provider. The SPI, the registries, the
first-`supports()` semantics and the in-memory conformance witness are all unchanged;
the tiers only remove the hand-written map for the common case.

**Consequences.** ADR 0002's "Deferred: attribute-driven auto-registration" note is
superseded by this ADR, and the getting-started zero-to-endpoint flow is now literally
true (witnessed by `tests/Feature/GettingStartedTest`). The auto layer registers only
the provider/persister pair — binding `EloquentRelationshipLoadState` (the lazy-relation
links-only render policy) remains part of explicit wiring, since force-binding it would
change rendering for existing hand-wired apps that deliberately leave it unbound.
