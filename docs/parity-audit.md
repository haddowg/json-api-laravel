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

**Baseline (verified 2026-07-05):** `composer test` → **925 tests / 7727 assertions green**;
`vendor/bin/phpstan --memory-limit=1G` → **no errors**; `composer cs-check` → **clean (463
files)**. Docker demo image **builds and serves** the full domain (`/api/albums`,
`/admin/albums`, `/docs.json` all `200`).

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
| custom-serializers-hydrators | standalone serializer/hydrator; **resource-level override** | standalone serializer **ported**; per-**resource** `serializer:`/`hydrator:` attribute override **not carried** | [custom-serializers-hydrators](custom-serializers-hydrators.md); `AsJsonApiSerializer`; `TrackResource`/`PlaylistResource` docblocks | ❌ **GAP** (see Findings) |
| relationships | related/relationship endpoints, mutation, include, pivot, RQ profile, countable | full; SQL push-down windowing; polymorphic to-many **over-parity** | [relationships](relationships.md); `Playlist`/`Album`/`Favorite`/`Library` resources | ✅ / 🟰 (over-parity) |
| pagination | page/offset/cursor, max-per-page | all three; keyset push-down; `max_per_page` clamp | [pagination](pagination.md), core `Pagination`, `CursorWidgetResource` | ✅ |
| validation | constraint bridge | always-on; full vocab map; filter-value validation | [validation](validation.md), `src/Validation` | 🟰 (always-on) |
| security + authorization | declarative authz | merged into **authorization**; policy-first + abilities + API-policy + per-relation | [authorization](authorization.md), `PlaylistApiPolicy` | 🟰 PLAN decision 7 |
| lifecycle + lifecycle-hooks | events + hook seam | 18 Laravel events + `ResourceLifecycleHooksTrait`; example `AuditLogSubscriber` **not ported** | [lifecycle](lifecycle.md), `src/Event`, `src/Hook` | 🟰 PLAN decision 10 / ❌ **GAP** (see F2) |
| actions | custom `-actions/{name}` | `#[AsJsonApiAction]` + `ActionContext`; 3 example actions | [actions](actions.md), `MusicCatalog/Action/*` | ✅ |
| atomic-operations | atomic extension, lid | `POST /operations`, `DB::transaction`, lid registry, After* deferral | [atomic-operations](atomic-operations.md), `src/Atomic` | ✅ |
| errors | route-scoped rendering | invokable renderer; core/HTTP/authz/500 arms; **exception-mapper seam** | [errors](errors.md), `src/Exception/ExceptionMapperInterface` | ✅ |
| openapi | document, UI, exports, byte-compat | full; `/docs.json`,`/schemas.json`,`/docs`; `jsonapi:openapi:export`/`jsonschema:export`; byte-compat CI | [openapi](openapi.md), `tests/ByteCompat`, `bin/byte-compat.php` | ✅ |
| multi-server-and-testing | N-server + testing | servers map; trait + macros; `actingAs()` native; schema conformance | [multi-server-and-testing](multi-server-and-testing.md) | 🟰 (testing reshaped) |
| capability-composition | independent capabilities | serializer/hydrator/relations/provider/persister composable; one-model-two-types | [capability-composition](capability-composition.md) | ✅ |
| — (new) optimize | cache warmers | **`optimizes()` pipeline** + `jsonapi:optimize`/`jsonapi:clear` + servability validation | [optimize](optimize.md), `src/Console/OptimizeCommand` | 🟰 PLAN divergence (optimize vs warmers) |

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
| 20 | pluggable id encode/decode | `encodeUsing(IdEncoder)` | `ProductResource` + `ProductIdCodec` | ✅ |
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

### F1 — Per-resource serializer/hydrator override on `#[AsJsonApiResource]`

**What:** the Symfony bundle lets `#[AsJsonApiResource(serializer: X::class, hydrator: Y::class)]`
point a resource at a hand-written serializer/hydrator class (e.g. to inject a bound
constructor argument surfaced in `meta`, or to make a request-aware attribute). This package's
`#[AsJsonApiResource]` does **not** yet carry those parameters — the attribute docblock states
"custom serializer/hydrator overrides … added in later phases", and the workbench `TrackResource`
(a bundle serializer override) and `PlaylistResource` (a bundle hydrator override) note the
absence explicitly.

**Severity:** low / contained. Not authorized by any PLAN divergence row or ADR, so it is a
genuine finding — but its impact is bounded:

- The **standalone** serializer capability (`#[AsJsonApiSerializer]`, ADR 0011) is ported and
  covers a resource-**less** type fully.
- The workbench reproduces both example cases' **OpenAPI projection and attribute shape**
  through supported seams — the `tracks` shape via the default serializer + per-field closures,
  and the `playlists` derivations via the [hook trait](lifecycle-hooks.md) — so the
  **byte-compat OpenAPI diff is unaffected**. The bundle's `TrackSerializer` additionally emits
  two serializer-override *runtime extras* on `GET /tracks/*` — a resource `meta.served_by` (its
  DI-bound catalogue tag) and a request-aware `nowPlaying` attribute for authenticated requests
  — which the Laravel `TrackResource` does not reproduce; those extras are part of this gap
  (neither projects to OpenAPI, so byte-compat still holds).
- It is documented as a known gap in
  [custom-serializers-hydrators](custom-serializers-hydrators.md).

**Recommendation:** carry `serializer:`/`hydrator:` (and a standalone `#[AsJsonApiHydrator]`)
on the attribute in a follow-up, mirroring bundle ADR 0023, so a DI-bound override is a
first-class one-liner rather than a hook/closure workaround. This does not block v1: the
capability is reachable today, and parity of the *rendered surface* holds.

### F2 — The example's `AuditLogSubscriber` is not ported to the workbench

**What:** the Symfony example ships an `AuditLogSubscriber` (a blueprint §1.14 "port to
`Event::listen`" item): it gates a write on an `X-Read-Only: on` header via `ServingEvent`
(a `403` on writes) and appends audit entries on `AfterSaveEvent`/`AfterDeleteEvent`. The
Laravel workbench does not carry an equivalent listener set; the only mention was a sentence in
[lifecycle](lifecycle.md), now reworded to attribute the subscriber to the Symfony example.

**Severity:** low / illustrative. The **event + hook machinery it would use is fully ported**
(18 events, `Event::listen`, `ServingEvent`, `AfterSaveEvent`/`AfterDeleteEvent` — decision 10),
so the omission is a missing *example wiring*, not a missing capability: an app writes exactly
this subscriber as a plain `Event::listen` set today. It has no bearing on byte-compat (audit
listeners never project to OpenAPI). `OverridingArtistProvider` (a blueprint-*recommended*,
optional example) is likewise unported — lower stakes as it was optional.

**Recommendation:** port `AuditLogSubscriber` (and optionally `OverridingArtistProvider`) into
the MusicCatalog wiring as `Event::listen` listeners with a smoke test in a follow-up, so the
workbench demonstrates the cross-cutting-listener pattern end-to-end. Not a v1 blocker.

## E. Composite attribute types (post-audit addendum, 2026-07-05)

The composite rollout (core #128–#131) landed in both packages after the original audit:

| Concern | Bundle | Laravel | Parity |
|---|---|---|---|
| `Obj` child cascade | `nestedCollection` (Map ∥ Obj), ADR 0111 | `mapChildRules` (Map ∥ Obj), ADR 0012 | ✅ identical `/data/attributes/<field>/<child>` pointers (twin conformance cases assert the same pointers) |
| `OneOf` variant validation | document-level `oneOfErrors()` | document-level `oneOfErrors()` | ✅ identical pointers incl. the unknown-discriminator `422` at `/<field>/<discriminator>`; violation *details* differ by host validator (by design) |
| `Shape` value validation | core `SchemaValueValidator`, DI-gated on opis, ADR 0112 | same core validator, `class_exists`-gated, ADR 0013 | ✅ one shared implementation — identical errors and pointers |
| Storage | single Doctrine `json` column | single `json` column + `array` cast | 🟰 recorded storage twins |
| Showcase resource | example `releases` (json-api-symfony#108) | workbench `releases` twin | ✅ byte-compatible OpenAPI (covered by `composer byte-compat` on both servers) |

## Summary

Every bundle capability is either **ported (✅)**, **intentionally reshaped/diverged by a
recorded PLAN decision or ADR (🟰)**, or **not shipped by the bundle itself (⏭)** — with two
genuine, low-severity gaps: **F1**, the per-resource serializer/hydrator attribute override
(byte-compat unaffected, reachable via other seams), and **F2**, the example
`AuditLogSubscriber` example-wiring omission (the underlying event machinery is fully ported).
Both are contained and documented; neither blocks v1. The over-parity items (polymorphic
to-many on the reference provider; `UniqueEntity` via `Rule::unique`; always-on validation)
exceed the bundle's surface by design.
