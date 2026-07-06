# Parity audit — `json-api-laravel` vs the Symfony bundle

This audit maps every capability of `haddowg/json-api-symfony` (and the Laravel gap docs it
carries) onto its status in this package. It is the Phase-5 parity-checklist deliverable. The
source-of-truth axes, per the phase blueprint, are: (A) the bundle `composer.json` `suggest`
map, (B) the bundle doc-page feature set, (C) the `laravel-gap-build-plan.md` build list, and
(D) this package's ADRs 0003–0011.

**Verdict legend**

| Symbol | Meaning |
| --- | --- |
| ✅ | **PARITY** — covered natively, same observable behaviour |
| 🟰 | **DIVERGENCE (by decision)** — intentionally different, authorized by a PLAN divergence row or an ADR |
| ⏭ | **DEFERRED** — not shipped by the bundle either (a recipe or a post-1.0 item); no Laravel gap |
| ❌ | **GAP** — a bundle capability not yet on the Laravel surface, **not** covered by a recorded divergence (a finding) |

**Baseline (refreshed 2026-07-06):** `composer test` → **1087 tests / 10666 assertions green**;
`vendor/bin/phpstan --memory-limit=1G` → **no errors**; `composer cs-check` → **clean (573
files)**; `composer byte-compat` → **byte-identical on both servers**. Docker demo image
**builds and serves** the full domain (`/api/albums`, `/admin/albums`, `/docs.json` all `200`).
(The original audit figures — 925 tests / 7727 assertions / 463 files, verified 2026-07-05 —
predated PRs #9–#22; the metrics above supersede them.)

## 0. The "four core-dependent pins/markers" — CONFIRMED RESOLVED

The task brief flagged four core-dependent items to update. Re-reading confirms they are
**already resolved** (the core sync happened before this phase); this audit verifies, it does
not re-open them.

| Item | Evidence | Status |
| --- | --- | --- |
| Null-in-comparison semantics (ADR 0003) | `tests/Unit/DataProvider/InMemoryDataProviderFilterTest.php` asserts the converged ADR-0116 semantics (nulls excluded) and passes; vendored core carries ADR 0116 | ✅ resolved via core |
| ordered-comparison-vs-null | `tests/Conformance/ReadConformanceTestCase.php` — "ADR 0003 … now RESOLVED (core ADR 0116)" | ✅ resolved |
| `ResourceIdConflict` 409 | `tests/Conformance/WriteConformanceTestCase.php` — conflict-family cell resolved | ✅ resolved |
| `AttributeValueInvalid` 422 | same, asserted directly | ✅ resolved |

The only two `markTestSkipped` in the suite are **opis-gating** (`SchemaConformanceTrait`,
`JsonApiAssertions`), not core gaps.

> **Core ADR-numbering note:** core `main` briefly carried two ADRs numbered **0116**. The
> ordered-comparison-vs-null decision (cited above and in the D-axis) **stays 0116**; the
> unrelated async-affordances ADR that collided with it has been **renumbered 0125** in a
> sibling core PR. Every "ADR 0116" reference in this audit is the ordered-comparison one.

## A. `suggest`-map axis (bundle optional dependencies)

| Bundle `suggest` | Bundle role | Laravel status | Evidence | Verdict |
| --- | --- | --- | --- | --- |
| `doctrine/orm` | reference data layer | Eloquent reference layer, always-on | `src/DataProvider/Eloquent`, `src/DataPersister/Eloquent` | 🟰 PLAN "examples/ → workbench" + native layer |
| `symfony/validator` | validator bridge (opt-in) | **always-on** illuminate/validation | `src/Validation`, [validation](validation.md) | 🟰 PLAN divergence (validator always-on) |
| `symfony/security-core` + `symfony/expression-language` | expression security | **policies + abilities + API-policy** | `src/Authorization`, [authorization](authorization.md) | 🟰 PLAN divergence (policies vs expressions) |
| `symfony/doctrine-bridge` (`UniqueEntity`) | post-hydration unique | **`Rule::unique` pre-hydration** (seam retained) | `Validation/Constraint/UniqueEntity`, `UserResource` | 🟰 PLAN divergence (Rule::unique pre-hydration) |
| `egulias/email-validator` | strict email | Laravel `email` rule; `Email->strict()` | `UserResource` email field | ✅ native |
| `opis/json-schema` | schema linter + testing conformance | **kept as `suggest`** | `composer.json` suggest; `src/Testing/SchemaConformanceTrait` | ✅ parity (same opt-in) |
| `symfony/intl` | reference-data (countries) | **kept** (workbench dev dep) | `require-dev` symfony/intl; `MusicCatalog/Provider/CountryProvider` | 🟰 PLAN decision 14 (kept for byte-compat) |
| `symfony/framework-bundle` + `symfony/browser-kit` (`JsonApiBrowser`) | functional testing | **`InteractsWithJsonApi` trait + TestResponse macros** | `src/Testing/InteractsWithJsonApi`, `JsonApiAssertions` | 🟰 PLAN divergence (trait+macros vs Browser) |
| `symfony/yaml` | YAML OpenAPI export | class-guarded YAML export in the exporter | `src/Console/OpenApiExportCommand` (`--format=yaml`) | ✅ native |

## B. Doc-page feature axis (each bundle page → Laravel coverage)

| Bundle page | Feature axis | Laravel status | Evidence | Verdict |
| --- | --- | --- | --- | --- |
| install / getting-started | discovery + first endpoint | zero-config `app/JsonApi` scan; auto-routes | [install](install.md), [getting-started](getting-started.md) | ✅ |
| resources | `#[AsJsonApiResource]`, id strategies, computed/write-only, headers | full; id matrix (auto/natural/uuid/ulid/encoded); cache + deprecation headers | [resources](resources.md); `Genre`/`Device`/`Product` resources | ✅ |
| routing | route loader, names, id patterns | auto-registration + `Route::jsonApi()` macro + `matchAs`→`where`; `route:cache`-safe | [routing](routing.md), `src/Routing/RouteRegistrar` | 🟰 PLAN decision 4 (auto vs import) |
| configuration | config tree | `config/jsonapi.php` (Laravel tree, not YAML) | [configuration](configuration.md) | 🟰 idiom |
| data-layer + doctrine | reference provider/persister, filters, EXISTS | merged into **eloquent** page; where/whereHas/whereThrough/window push-down | [eloquent](eloquent.md), `EloquentFilterHandler` | ✅ (reshaped) |
| custom-data-providers | SPI | ported SPI + `AbstractDataProvider` + in-memory witness + custom providers | [custom-data-providers](custom-data-providers.md), `src/DataProvider` | ✅ |
| custom-serializers-hydrators | standalone serializer/hydrator; **resource-level override** | standalone serializer **and** hydrator **ported** (ADR 0011/0018); per-**resource** `serializer:`/`hydrator:` attribute override **carried** (ADR 0015) | [custom-serializers-hydrators](custom-serializers-hydrators.md); `AsJsonApiSerializer`/`AsJsonApiHydrator`; `ResourceSerializerHydratorOverrideTest`, `StandaloneHydratorWriteTest` | ✅ (F1 resolved 2026-07-05) |
| relationships | related/relationship endpoints, mutation, include, pivot, RQ profile, countable | full; SQL push-down windowing; polymorphic to-many **over-parity** | [relationships](relationships.md); `Playlist`/`Album`/`Favorite`/`Library` resources | ✅ / 🟰 (over-parity) |
| pagination | page/offset/cursor, max-per-page | all three; keyset push-down; `max_per_page` clamp | [pagination](pagination.md), core `Pagination`, `CursorWidgetResource` | ✅ |
| validation | constraint bridge | always-on; full vocab map; filter-value validation | [validation](validation.md), `src/Validation` | 🟰 (always-on) |
| security + authorization | declarative authz | merged into **authorization**; policy-first + abilities + API-policy + per-relation | [authorization](authorization.md), `PlaylistApiPolicy` | 🟰 PLAN decision 7 |
| lifecycle + lifecycle-hooks | events + hook seam | 18 Laravel events + `ResourceLifecycleHooksTrait`; example `AuditLogSubscriber` **ported** to the workbench | [lifecycle](lifecycle.md), `src/Event`, `src/Hook`, `MusicCatalog/Listeners/AuditLogSubscriber` | 🟰 PLAN decision 10 / ✅ (F2 resolved 2026-07-05) |
| actions | custom `-actions/{name}` | `#[AsJsonApiAction]` + `ActionContext`; 3 example actions | [actions](actions.md), `MusicCatalog/Action/*` | ✅ |
| atomic-operations | atomic extension, lid | `POST /operations`, `DB::transaction`, lid registry, After* deferral | [atomic-operations](atomic-operations.md), `src/Atomic` | ✅ |
| errors | route-scoped rendering | invokable renderer; core/HTTP/authz/500 arms; **exception-mapper seam** | [errors](errors.md), `src/Exception/ExceptionMapperInterface` | ✅ |
| openapi | document, UI, exports, byte-compat | full; `/docs.json`,`/schemas.json`,`/docs`; `jsonapi:openapi:export`/`jsonschema:export`; byte-compat CI | [openapi](openapi.md), `tests/ByteCompat`, `bin/byte-compat.php`; post-audit correctness fix #11 — standalone `#[AsJsonApiSerializer]` types are now included in the `schemas.json` export **and** the servability warmer (previously omitted); the bundle was verified unaffected | ✅ (corrected by #11) |
| multi-server-and-testing | N-server + testing | servers map; trait + macros; `actingAs()` native; schema conformance | [multi-server-and-testing](multi-server-and-testing.md) | 🟰 (testing reshaped) |
| capability-composition | independent capabilities | serializer/hydrator/relations/provider/persister composable; one-model-two-types | [capability-composition](capability-composition.md) | ✅ |
| — (new) optimize | cache warmers | **`optimizes()` pipeline** + `jsonapi:optimize`/`jsonapi:clear` + servability validation | [optimize](optimize.md), `src/Console/OptimizeCommand`; post-audit correctness fix #17 — the warmer no longer false-flags `extractUsing`/`storedAs` relations as unservable; the bundle was verified unaffected | 🟰 PLAN divergence (optimize vs warmers) |

## C. Gap-build-plan axis (`laravel-gap-build-plan.md` — the pre-v1 build list)

These are core/bundle gaps closed pre-1.0. The audit confirms the Laravel surface exposes each
(most are core-level and inherited through the vendored core).

| Gap # | Capability | Laravel status | Evidence | Verdict |
| --- | --- | --- | --- | --- |
| 1 | per-operation lifecycle hooks | ported (18 events + hook trait) | `src/Hook`, `src/Event`, [lifecycle-hooks](lifecycle-hooks.md) | ✅ |
| 3 | per-op authz seeing the loaded entity | policy-first authorizer | `src/Authorization/Authorizer`, ADR 0004 | ✅ / 🟰 |
| 4 | filter-value validation seam | `FilterValueValidator` | `src/Validation/FilterValueValidator` | ✅ |
| 6 | resource `self` link by convention (+ opt-out) | on by default; `emitsSelfLink(): false` opt-out | `DeviceResource::emitsSelfLink()` | ✅ |
| 8 | `defaultSort()` when no `?sort` | supported | `AlbumResource::defaultSort()` | ✅ |
| 9 | max-per-page cap | `pagination.max_per_page` clamp | `config/jsonapi.php`, [pagination](pagination.md) | ✅ |
| 9b | include-path safeguard (`maxDepth` + `cannotBeIncluded`) | both | `max_include_depth`; `FavoriteResource` `cannotBeIncluded()`; `UserResource` `getAllowedIncludePaths()` | ✅ |
| 10 | countable to-many (`?withCount`) | `countable()` + `countRelated()` seam | `AlbumResource` tracks `countable()`; SPI `countRelated` | ✅ |
| 11/11a/11b/11c | testing assertions (status/content-type/exact/collection/created) | TestResponse macros + document assertions | `src/Testing/JsonApiAssertions` | ✅ (reshaped) |
| 13 | per-relationship meta builder | relationship meta / pivot meta render | ADR 0008; `Playlist.orderedTracks` | ✅ |
| 15 | PATCH merge-before-validate | pivot merge-before-validate; post-hydration seam | [relationships](relationships.md#pivot-belongstomany-data), [validation](validation.md) | ✅ |
| 17 | conditional `readOnly(fn)`/`hidden(fn)` | core field closures | core fields | ✅ (core) |
| 19 | custom id route pattern + `ulid()` | `matchAs()`→`where`; `ulid()->generated()` | `Product`/`Device` resources, [routing](routing.md) | ✅ |
| 20 | pluggable id encode/decode | `encodeUsing(IdEncoder)` | `ProductResource` + `ProductIdCodec`; the original ✅ covered **encode only** — reads and linkage writes passed the raw wire token straight to storage until [ADR 0014](adr/0014-encoded-id-decode-is-a-reference-eloquent-layer-concern.md) (#13) made decode a reference-Eloquent-layer concern (`EloquentEncodedIdTest`), matching the bundle's pre-existing ADR 0038 posture | ✅ (encode+decode since #13) |
| 24 | sort by relationship count | core sort vocabulary | core | ✅ (core) |
| 26 | dynamic `baseUri()` | `base_uri` config (+ request-derived default) | `config/jsonapi.php` | ✅ |
| 27 | disallow/require pagination | paginator policy | core pagination | ✅ (core) |
| 28 | simple/no-total pagination | cursor + offset count-free modes | core `Pagination` | ✅ (core) |
| 31 | per-relationship extra filters (`withFilters`) | `->withFilters()` on relations | `AlbumResource`/`PlaylistResource` | ✅ |
| 37 | reject unknown sparse fieldsets (opt-in) | strict query params | `strict_query_parameters` | ✅ |
| 38 | extensible exception→JSON:API pipeline | tagged `ExceptionMapperInterface` | `src/Exception/ExceptionMapperInterface` | ✅ |
| 40 | `identifierMeta()` (linkage meta) | core identifier meta | core serializer | ✅ (core) |
| 42 | error localisation | validator 422 via Laravel translator; core message keys | [validation](validation.md) | ✅ (validator) / ⏭ (spec-exception locale) |
| 43 | polymorphic to-many shared filter/sort vocab | renders; filter/sort 400 (no shared vocab) | `LibraryResource`, [relationships](relationships.md#polymorphic-relations) | ✅ render / ⏭ shared-vocab |
| 44/45 | error-status + exact-error assertions | error assertion macros | `src/Testing/JsonApiAssertions` | ✅ |
| 46 | typed test query DSL | `PendingJsonApiRequest` typed builders | `src/Testing/PendingJsonApiRequest` | ✅ |
| 47 | `expectResource(object)` binding | model→resource assertion | `src/Testing` | ✅ (reshaped) |
| 2 | soft-delete capability | **bundle recipe, not built** — no Laravel gap | gap-build-plan §3 recipe | ⏭ deferred (recipe) |
| 22 | attribute flattened from related model | **bundle defers past v1** | gap-build-plan §1 ("defer past v1") | ⏭ deferred |
| 52/57/58/59/60/62/63/66/29/39 | low-value core niceties | inherited from core where shipped | core | ✅/⏭ (core-level) |

## D. Laravel ADR axis (recorded divergences 0003–0011)

| ADR | Decision | Reconciliation | Verdict |
| --- | --- | --- | --- |
| 0003 | null-in-comparison is a witness divergence, resolved in core | resolved via core ADR 0116; suite asserts converged semantics | ✅ resolved |
| 0004 | write authz on the pristine subject, before hydration | `Authorizer` timing | 🟰 by ADR |
| 0005 | `?include` batching via the SPI, not `with()` | provider-agnostic batcher | 🟰 by ADR |
| 0006 | windowed batches SQL push-down only (no toggle) | `EloquentDataProvider::fetchWindowedBatch()` | 🟰 PLAN decision 9 |
| 0007 | morph alias decoupled from JSON:API type | `Favorite`/`Library` morph resolution | 🟰 by ADR |
| 0008 | pivot-meta read render landed in Phase 3b | `Playlist.orderedTracks` pivot render | ✅ landed |
| 0009 | embedded belongsToMany after create | whole-resource write ordering | 🟰 by ADR |
| 0010 | relationship-endpoint query it can't honour → 400 | reject-not-ignore | 🟰 by ADR |
| 0011 | standalone serializer capability | `#[AsJsonApiSerializer]` (charts/countries) | ✅ ported |

## Findings (❌ GAPs not covered by a recorded divergence)

### F1 — Per-resource serializer/hydrator override on `#[AsJsonApiResource]` — ✅ RESOLVED (2026-07-05)

**Was:** the Symfony bundle lets `#[AsJsonApiResource(serializer: X::class, hydrator: Y::class)]`
point a resource at a hand-written serializer/hydrator class (e.g. to inject a bound
constructor argument surfaced in `meta`); this package's attribute did not carry those
parameters. Byte-compat was never affected — the workbench reached both example cases'
projected shape via field closures + the [hook trait](lifecycle-hooks.md).

**Resolved by [ADR 0015](adr/0015-per-resource-serializer-hydrator-override.md)**, mirroring
bundle ADR 0023: the attribute now carries `serializer:`/`hydrator:`; discovery validates each
override against its core contract and snapshots the class-strings on the `ResourceDescriptor`
(so they survive `jsonapi:optimize`); the `ServerFactory` threads them into core's
`Server::register()`, which container-resolves the override — bound constructor arguments and
`SerializerResolverAwareInterface` injection included. Proven end-to-end over HTTP (a DI-bound
serializer read, a DI-bound hydrator write, the other concern staying field-driven each time,
and the optimize snapshot) in `tests/Feature/ResourceSerializerHydratorOverrideTest`;
documented in [custom-serializers-hydrators](custom-serializers-hydrators.md). Out of scope,
deliberately: a standalone `#[AsJsonApiHydrator]` (no bundle example exercises it — since
carried anyway by [ADR 0018](adr/0018-standalone-hydrator-capability.md), 2026-07-05, with
dedicated fixtures rather than a workbench change) and the bundle `TrackSerializer`'s runtime
extras (`meta.served_by`, `nowPlaying`) in the workbench — the capability is what parity
required, and byte-compat pins the projected document.

### F2 — The example's `AuditLogSubscriber` is not ported to the workbench — ✅ RESOLVED (2026-07-05)

**What:** the Symfony example ships an `AuditLogSubscriber` (a blueprint §1.14 "port to
`Event::listen`" item): it gates a write on an `X-Read-Only: on` header via `ServingEvent`
(a `403` on writes) and appends audit entries on `AfterSaveEvent`/`AfterDeleteEvent`. The
Laravel workbench did not carry an equivalent listener set; the only mention was a sentence in
[lifecycle](lifecycle.md) attributing the subscriber to the Symfony example.

**Severity:** low / illustrative. The **event + hook machinery it uses was already fully ported**
(18 events, `Event::listen`, `ServingEvent`, `AfterSaveEvent`/`AfterDeleteEvent` — decision 10),
so the omission was a missing *example wiring*, not a missing capability. It has no bearing on
byte-compat (audit listeners never project to OpenAPI).

**Resolution (2026-07-05):** ported as `Workbench\App\MusicCatalog\Listeners\AuditLogSubscriber`
(a plain Laravel event subscriber, `Event::subscribe()`-registered by **both** wiring providers)
appending to the singleton `Support\AuditLog` store — one divergence from the bundle, recorded
in the subscriber docblock: the deleted wire id is read directly off the entity in `AfterDelete`
(Doctrine erases identifiers post-flush, Eloquent models and POPOs do not, so no `BeforeDelete`
capture is needed). Exercised end to end on both provider arms by
`tests/Feature/MusicCatalog/{Eloquent,InMemory}AuditListenerTest` (committed create/update/delete
each append one entry; a `409`-guarded delete and a denied ability append none; the read-only
header freezes writes while reads pass); the byte-compat gate stays green, proving the listeners
are runtime-only. `OverridingArtistProvider` (a blueprint-*recommended*, optional example)
remains unported by choice: provider priority-shadowing is already witnessed in this workbench —
`ChartProvider`/`CountryProvider` at priority `0` over the `-128` reference fallback — plus
`DataProviderRegistryTest` and the [custom-data-providers](custom-data-providers.md)
"Priority and shadowing" section, so a delegating overlay provider would add no new evidence.

### F3 — Attribute-driven auto-registration of the reference pair (the bundle's `entity:`) — ✅ RESOLVED (2026-07-05)

**Was:** not an audit finding but a recorded deferral ([ADR 0002](adr/0002-port-the-provider-persister-spi.md),
"Deferred: attribute-driven auto-registration" — "tracked for the Phase 5 parity audit"): the
bundle's `#[AsJsonApiResource(entity: …)]` + `DoctrineEntityMapPass` builds the
`type → entity` map and auto-registers its reference Doctrine provider, so a bundle app
writes no data wiring; this package required the `type → model` map to be constructed by
hand, so the getting-started "maps the type by convention" sentence was not yet true.

**Resolved by [ADR 0019](adr/0019-three-tier-model-mapping-with-an-auto-registered-reference-pair.md)**:
`#[AsJsonApiResource(model: …)]` (the `entity:` twin, scan-time guarded, snapshot-carried)
plus a convention tier (`albums` → `App\Models\Album` under `jsonapi.eloquent.model_namespace`)
feed a `ModelMapResolver` whose map auto-registers the reference Eloquent pair at `-256` —
below the documented `-128` explicit floor, claiming only mapped types. Over-parity: the
bundle has no convention tier (Doctrine entity naming carries no equivalent convention).
Witnessed end-to-end by `tests/Feature/GettingStartedTest` (the documented flow verbatim,
zero wiring) and `tests/Feature/ModelMappingTiersTest` (each tier + the untouched
no-provider failure); documented in [eloquent](eloquent.md#the-model-map-three-tiers).

### F4 — Sparse-by-default fields (core #126 / ADR 0117) — ✅ RESOLVED (2026-07-06)

**What:** core PR #126 (ADR 0117) added a sparse-by-default field tier — a field declared
`sparseByDefault()` is omitted from a resource's `attributes` **unless** the client explicitly
names it in a `fields[type]` member (the opt-in inverse of the usual sparse-fieldset rule,
orthogonal to `hidden()`/`writeOnly()`). The Symfony bundle witnesses it over HTTP
(`tests/Functional/SparseByDefaultFieldTest` + a `Sparse` fixture kernel, bundle #105) and
documents it in `resources.md`. This package inherited the core capability through the
vendored core but carried **no witness and no docs** for it.

**Severity:** low / coverage-and-documentation. The behaviour was already correct — it lives
entirely in core's `AbstractResource` attribute render — so the gap was a missing Laravel-side
witness + doc, not a missing capability. No byte-compat bearing: the field renders on no
document unless requested, so the music-catalog export is untouched.

**Resolved by this PR:** a dual-provider HTTP conformance suite
(`tests/Conformance/SparseByDefaultConformanceTestCase` + in-memory and Eloquent concretes)
asserts the `sparseWidgets` resource's `expensiveScore` attribute is absent by default,
present when named in `fields[sparseWidgets]`, and stays absent when only `name` is named — on
**both** providers. The fixture lives in a dedicated `Workbench\App\Sparse` namespace (+ a
`sparse_widgets` migration for the Eloquent arm), isolated from the music-catalog workbench so
`composer byte-compat` stays byte-identical. Documented in
[resources](resources.md#sparse-by-default-fields).

## E. Composite attribute types (post-audit addendum, 2026-07-05)

The composite rollout (core #128–#131) landed in both packages after the original audit:

| Concern | Bundle | Laravel | Parity |
|---|---|---|---|
| `Obj` child cascade | `nestedCollection` (Map ∥ Obj), ADR 0111 | `mapChildRules` (Map ∥ Obj), ADR 0012 | ✅ identical `/data/attributes/<field>/<child>` pointers (twin conformance cases assert the same pointers) |
| `OneOf` variant validation | document-level `oneOfErrors()` | document-level `oneOfErrors()` | ✅ identical pointers incl. the unknown-discriminator `422` at `/<field>/<discriminator>`; violation *details* differ by host validator (by design) |
| `Shape` value validation | core `SchemaValueValidator`, DI-gated on opis, ADR 0112 | same core validator, `class_exists`-gated, ADR 0013 | ✅ one shared implementation — identical errors and pointers |
| Storage | single Doctrine `json` column | single `json` column + `array` cast | 🟰 recorded storage twins |
| Showcase resource | example `releases` (json-api-symfony#108) | workbench `releases` twin | ✅ byte-compatible OpenAPI (covered by `composer byte-compat` on both servers) |

## F. Async write seam (post-audit addendum, 2026-07-06)

The Symfony bundle grew an async-write seam after the original audit (bundle PR #104,
bundle ADR 0110); this seam is now twinned here (ADR 0020):

| Concern | Bundle | Laravel | Parity |
|---|---|---|---|
| Accept marker | `DataPersister\AcceptedForProcessing` (`poll`/`withJob`/`withRetryAfter`/`withMeta`) | same class, `haddowg\JsonApiLaravel\DataPersister` namespace | ✅ identical fluent API |
| `202` render | `CrudOperationHandler::accepted()` → core `AcceptedResponse` on create/update | same arm, same core response VO | ✅ byte-identical `202` (job resource / meta-only, `Content-Location`, `Retry-After`) |
| `303` completion | `ActionContext::seeOther()` → core `SeeOtherResponse` | same helper, same core response VO | ✅ byte-identical `303` (empty body, `Location`) |
| Atomic rejection | `AsyncWriteNotAllowedInAtomicOperation` (`422`, `ASYNC_WRITE_IN_ATOMIC_OPERATION`) | same exception + code | ✅ identical error; batch rolls back |
| Recipe | Symfony Messenger (`docs/async.md`) | Laravel queued jobs ([async](async.md)) | 🟰 same wire contract, framework-idiomatic recipe (both unopinionated about the queue) |
| Witness | `AsyncWriteTest` (in-memory kernel) | dual-provider `AsyncWriteConformanceTestCase` (in-memory + Eloquent) | ✅ over-parity: the seam is exercised on **both** providers here |

Two consequences of the reference storage model are recorded in ADR 0020, not divergences
from the bundle's contract: on an accepted whole-resource **create**, the deferred
(join / inverse-FK) embedded-relationship applies are skipped (nothing was keyed — the
bundle applies embedded relationships pre-create, so it never had a deferred tail); and the
conformance suite does not assert an accepted **update** leaves its target unchanged on a
re-read (the in-memory witness hydrates by reference, so its read reflects the uncommitted
hydration while Eloquent's re-query would not — the bundle's `AsyncWriteTest` makes no such
claim either). OpenAPI does not yet document the async `202`/`303` responses on either side.

## G. Cursor-completeness rollout (post-audit addendum, 2026-07-06)

The keyset (cursor) surface grew to full coverage after the original audit, each shape twinned
with the bundle on one hoisted core engine:

| Endpoint shape | Bundle | Laravel | Parity |
|---|---|---|---|
| Related-collection cursor | ADR 0113 | ADR 0016 | ✅ dual-provider conformance (`RelatedCursorConformanceTestCase`) |
| Pivot-related + linkage cursor | ADR 0114 | ADR 0017 | ✅ dual-provider conformance (`PivotCursorConformanceTestCase`, `LinkageCursorConformanceTestCase`) |
| Shared keyset engine | core `Collection\Keyset` (core #136 / ADR 0123) | same hoisted core keyset, consumed by the Eloquent + in-memory providers | ✅ one implementation, both hosts |
| Linkage page profile advertisement | core `IdentifierResponse::withPage` (core #137 / ADR 0124) | same core response VO | ✅ identical `page` profile on a linkage document |

Related, pivot-related, and linkage cursor pages all ride core's hoisted `Collection\Keyset` —
the cursor logic lives once in core and both packages consume it — while the Laravel side
proves each shape on **both** providers (Eloquent keyset push-down + the in-memory witness),
over-parity to the bundle's single-provider functional tests. Linkage cursor pages advertise
the `page` profile through core's `IdentifierResponse::withPage`, so a linkage document carries
the same profile link/meta as a full-resource collection page.

## H. Reverse-parity note (post-audit addendum, 2026-07-06)

Parity now flows both directions across one core seam. This package **consumes** core's public
`castWireValue` API (core ADR 0122) in the reference persister: `EloquentDataPersister` casts
an incoming pivot/attribute wire value through the field's own
`FieldInterface::castWireValue()`
(`src/DataPersister/Eloquent/EloquentDataPersister.php`) rather than re-deriving the coercion.
The mirror adoption on the bundle side — `DoctrineDataPersister::coercePivotValue` moving onto
the same core API — was **pending at audit time** and is landing in a sibling bundle PR; the
two reference persisters then share one core coercion contract.

## I. Native-rule + self-applying-filter carriers (post-audit addendum, 2026-07-06)

Twin of bundle PR #101 (bundle ADRs 0108/0109), landing the two first-party `constrain()`
escape hatches here as Laravel ADRs **0021/0022**:

| Bundle construct | Laravel twin | Notes |
| --- | --- | --- |
| `NativeConstraints` (wraps native Symfony `Constraint`s) | **`LaravelRules`** (`src/Validation/Constraint/LaravelRules.php`) — wraps native `illuminate/validation` rules | ✅ `ConstraintTranslator` recognises it in the `match` and returns the wrapped rules verbatim (before `translateExtension`); runs in the same `422` pass and on `filter[…]` values via the shared translator; opt-in `->schema()` over core's neutral `Schema` VO (both carriers ride core `ProvidesJsonSchema`, so the fragment is byte-identical) |
| `AppliesToQueryBuilder` (self-applying Doctrine filter) | **`AppliesToEloquentQueryBuilder`** (`src/DataProvider/Eloquent/AppliesToEloquentQueryBuilder.php`) — self-applying Eloquent filter | ✅ `EloquentFilterHandler` consults it **before** the arm registry; the built-ins still win; Eloquent-only (undeclared on the in-memory provider → clean `400`) |

Neither touches the music-catalog workbench (the byte-compat witness), mirroring the bundle
PR, which did not touch its example app — so the export diff stays empty. Both are exercised
by unit tests (`ConstraintTranslatorTest`, `EloquentFilterHandlerTest`), the native rule
proven to actually validate through the shared pass.

## Summary

Every bundle capability is either **ported (✅)**, **intentionally reshaped/diverged by a
recorded PLAN decision or ADR (🟰)**, or **not shipped by the bundle itself (⏭)**. The audit
surfaced two genuine, low-severity gaps, both since **resolved (2026-07-05)**: **F1**, the
per-resource serializer/hydrator attribute override (ADR 0015), and **F2**, the example
`AuditLogSubscriber` example-wiring omission — now ported to the workbench and exercised on
both provider arms. A third low-severity coverage gap, **F4** (the sparse-by-default field
tier, core ADR 0117, previously witnessed only by the bundle), is **resolved by this PR** with
a dual-provider conformance suite and docs. The over-parity items
(polymorphic to-many on the reference provider; `UniqueEntity` via `Rule::unique`; always-on
validation) exceed the bundle's surface by design.
