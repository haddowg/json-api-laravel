# vs json-api-server

[`tobyz/json-api-server`](https://github.com/tobyz/json-api-server) — by Toby Zerner, the
creator of Flarum — is the closest philosophical relative this package has: both are
typed-schema, spec-first designs where one set of schema objects drives serialization,
validation, *and* OpenAPI 3.1, both target JSON:API **1.1** with atomic operations and the
published cursor-pagination profile, and both treat capability composition as a design
principle rather than an afterthought. The differences are ones of investment: json-api-server
stays deliberately lean and framework-agnostic (a PSR-15 handler you wire by hand), while this
package invests in a deep Laravel-native layer — discovery, routing, artisan tooling, Gate
policies, a testing kit — plus a byte-identical cross-framework contract with the Symfony
bundle. It also, honestly, has a capability this package does not — a **top-level
heterogeneous listing endpoint**: a primary collection that returns mixed models via a union
query. (This package does support heterogeneous polymorphism at the *relationship* level —
`MorphTo` and mixed-type `MorphToMany` — just not as a top-level collection.) And there is one
this package *deliberately declines* rather than lacks: client-composed boolean filter algebra,
where this package holds filtering to an explicit, owner-vetted allow-list (see Reading, below).

Comparison as of 5 July 2026, against `tobyz/json-api-server` v1.0.0-rc.1. Found an error?
[Open an issue](https://github.com/haddowg/json-api-laravel/issues).

## Spec compliance

Here the two packages are kin, and it is worth crediting: json-api-server targets JSON:API
1.1, negotiates `ext`/`profile` media-type parameters strictly, implements the atomic
operations extension with `lid` support, and maintains a dedicated test suite mirroring the
spec chapter by chapter. The differences are at the edges — how profiles are registered, and
how atomicity and nested error pointers behave.

| Capability | This package | json-api-server |
| --- | --- | --- |
| JSON:API 1.1 | **Yes** — full 1.1 document structure and endpoint semantics, spec-compliance suite in the framework-agnostic core | **Yes** — targets 1.1 with a spec-mirroring test suite |
| Content negotiation | **Yes** — `ext`/`profile` parameters with `415`/`406` on violations and a formal profile registration system | **Yes** — strict `ext`/`profile` handling with a per-request profile context API; no formal profile registry (profiles are activated ad hoc in resource code) |
| Atomic operations | **Yes** — opt-in per server, all-or-nothing batches with `lid`, [lifecycle hooks](lifecycle.md) per operation; see [atomic operations](atomic-operations.md) | **Yes** — add/update/remove with `lid` and per-operation error pointers; all-or-nothing requires wrapping the handler in your own DB transaction |
| Error objects | **Yes** — pointer/parameter sources including nested pointers into composites (`/data/attributes/address/city`); see [errors](errors.md) | **Yes** — spec-shaped errors with a localizable, overridable catalogue; pointers stop at the field level (nested members surface via `meta`) |

## Resource definition

Both offer a declarative typed field system with storage mapping, visibility scoping, and
hooks — genuinely similar DSLs. This package's palette adds semantic types that *validate*
(`Email`, `Url`, `Uuid`, `Slug`, `Ip`), composite attributes with nested validation pointers,
and a bidirectional id-encoding seam; json-api-server's capability-interface design — a
resource opts into exactly the endpoints it exposes — is elegant, and this package's
[capability composition](capability-composition.md) pursues the same idea from the other
direction, down to a bare serializer/hydrator pair with no resource class at all.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Typed field system | **Yes** — including semantic `Email`/`Url`/`Uuid`/`Slug`/`Ip` types with real validation behaviour; see [resources](resources.md) | **Yes** — rich palette with enum/pattern/min/max constraints and schema combinators; `Str::format('email')` is an OpenAPI hint only, with no validation behaviour |
| Composite attributes | **Yes** — `Map` (nested wire object over flat columns), `Obj`, `OneOf` discriminated unions, and the `Shape` constraint, with nested `422` pointers; see [composite attributes](composite-attributes.md) | **Partial** — typed `Obj` plus `OneOf`/`AnyOf`/`AllOf` combinators reflected in OpenAPI; no map-over-flat-columns composite, and nested failures do not emit nested pointers |
| Encoded ids | **Yes** — an attachable encoder decouples wire id from storage key, honoured bidirectionally through serialization, filters, and both reference layers | **Partial** — the `Id` field can customise retrieval and serve a UUID column, but there is no bidirectional encoder abstraction; filters and linkage do not automatically honour a wire-vs-storage mapping |
| Capability composition | **Yes** — per-operation allow-lists, `readOnly` shorthand, per-relation endpoint gating, standalone serializer/hydrator registration; see [capability composition](capability-composition.md) | **Yes** — the core design: `endpoints()` lists exactly what is exposed, each backed by a small interface (`Findable`, `Listable`, `Creatable`, …), with per-relation and per-endpoint gating |

## Reading

Both validate includes against per-relation opt-ins, support sparse fieldsets, multi-field
sorting, and a declarative filter vocabulary. They diverge on *who composes a filter*:
json-api-server exposes a nestable boolean `filter[and]`/`[or]`/`[not]` algebra that lets the
client assemble predicates the server never named, while this package holds filtering to an
explicit, owner-vetted allow-list — every filter is a named, indexable query shape the
resource author wrote. That is a deliberate trade, not a missing feature: it keeps query cost
bounded and predictable (no client can compose an unindexed `OR`/`NOT` fan-out), matters most
under multi-tenancy where an ad-hoc filter tree is a cost-amplification surface, and keeps the
filter surface exporting cleanly to OpenAPI as discrete, documented parameters. The gaps run
the other way on bounding: cursor pagination coverage and how to-many includes behave at scale.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Fieldsets & includes | **Yes** — depth caps, per-relation opt-outs, and per-relationship sort/filter on included collections via the [Relationship Queries profile](relationships.md#the-relationship-queries-profile) | **Yes** — sparse fieldsets plus a `sparse()` flag, includes validated per relation; no depth caps and no per-relationship querying of included collections |
| Filtering | **Yes** — an explicit, owner-vetted allow-list: `Where`, `WhereIn`/`NotIn`, `WhereNull`, `WhereHas`/`WhereDoesntHave`, dotted-path `WhereThrough`, singular filters — every filter is a named, indexable query shape the author declared; client-composed algebra is declined by design (bounded query cost, multi-tenant safety); see [eloquent](eloquent.md#filters--query-builder) | **Yes** — a comparable named vocabulary, plus client-composed nestable boolean `filter[and]`/`[or]`/`[not]` groups and per-filter operators (`filter[views][gt]=100`); no dotted-path equivalent |
| Pagination strategies | **Yes** — page-number, offset, server-fixed, and cursor, with a per-relation defaults chain; see [pagination](pagination.md) | **Partial** — offset and cursor, both count-free by default with opt-in totals; no page-number or server-fixed strategies |
| Cursor pagination | **Yes** — true keyset under any declared `?sort`, on primary, related, *and* relationship linkage endpoints, advertising the published profile | **Yes** — true keyset via Laravel's `cursorPaginate`, correctly activating the published profile URI; not available on to-many linkage, and `after`+`before` ranges are unsupported in the Eloquent layer |
| Compound-document bounding | **Yes** — windowed includes and related collections via SQL window functions and push-down queries; see [eloquent](eloquent.md#windowed-relationship-queries--sql-push-down-only) | **No** — a deferred-value buffer batches relationship loading to avoid N+1, but to-many includes and linkage load the full related collection (the docs warn about this) |

## Writing & validation

CRUD, client-generated ids, and relationship mutation are covered on both sides. Validation is
where the shared philosophy diverges in depth: both derive validation from the type system,
but this package compiles the full constraint set to Laravel rules (or Symfony constraints)
with an entity-level pass and an optional document-first linter, while json-api-server's
Laravel `rules()` bridge validates values one at a time. Asynchronous processing, once a row
that belonged entirely to them, is now at full parity — both accept a write with `202` + a
pollable job resource and `Retry-After` and complete with `303 See Other`, and both reflect
that whole lifecycle in the generated OpenAPI document.

| Capability | This package | json-api-server |
| --- | --- | --- |
| CRUD & relationship mutation | **Yes** — five operations auto-exposed with correct status codes; Replace/Add/Remove with per-relation prohibition flags rejecting as typed `403`s; see [relationships](relationships.md#relationship-mutations-and-prohibitions) | **Yes** — endpoint objects per operation (`201` + `Location`, `204`), `PATCH`/`POST`/`DELETE` linkage with an `Attachable` contract; gating via writability rather than per-verb flags |
| Validation from the definition | **Yes** — constraints compile to Laravel rules with nested pointers, an entity-level post-hydration pass, and an optional JSON Schema linter; see [validation](validation.md) | **Partial** — type-system validation plus per-field `validate()` closures; the `rules()` bridge validates one value at a time (interdependent rules do not work) and rules are not reflected in OpenAPI |
| Lifecycle events & hooks | **Yes** — 18 real Laravel [events](lifecycle.md) plus a per-resource [hook trait](lifecycle-hooks.md); before hooks abort, after hooks replace the result | **Partial** — per-resource hook methods and endpoint-level callbacks; no framework event dispatch |
| Custom actions | **Yes** — declared on the resource with typed input modes, per-action authorization, and `asLink` exposure; see [actions](actions.md) | **Yes** — `CollectionAction`/`ResourceAction` endpoints with configurable methods and OpenAPI paths; no typed input/output modes |
| Async writes | **Yes** — a persister returns `AcceptedForProcessing` to defer a write to a queue; the handler renders `202` + a pollable job resource with `Retry-After`, and the job resource's fetch (or a completion action) returns `303 See Other` — the whole lifecycle reflected in OpenAPI via per-operation response declarations; see [async writes](async.md) | **Yes** — the full JSON:API Asynchronous Processing recommendation: `202` + job resource, `Retry-After`, `303 See Other` on completion, all reflected in OpenAPI |

## Data layer

json-api-server abstracts storage as interface methods implemented on the resource class
itself — any backend works, and its Eloquent layer is genuinely strong (polymorphic unions are
a design pillar). This package separates the concerns: reads and writes flow through
registered `DataProvider`/`DataPersister` services resolved by priority, so an application
provider cleanly shadows the [reference layer](eloquent.md) per type, and a reusable in-memory
pair ships as a test double and conformance witness (see
[custom data providers](custom-data-providers.md)).

| Capability | This package | json-api-server |
| --- | --- | --- |
| Provider/persister seam | **Yes** — separately registered services, priority + first-supports resolution, clean per-type shadowing | **Partial** — storage methods live on the resource class; any backend works, but there is no registered-service resolution or shadowing without subclassing |
| Reference layers | **Yes** — an auto-registered Eloquent layer with a three-tier type-to-model map (and a Doctrine ORM layer on the Symfony side), resolving polymorphic relationships (`MorphTo`, mixed-type `MorphToMany`) natively; see [eloquent](eloquent.md) | **Partial** — one Eloquent layer implementing all capabilities, including top-level polymorphic-union *listing* endpoints; registration is manual, with no attribute or convention-based mapping |
| In-memory provider | **Yes** — ships with the package; the docs' example suites run over both it and the database layer; see [workbench](workbench.md) | **No** — array-backed mocks exist only in the internal test suite, not as a shipped consumer tool |

## OpenAPI & tooling

Both generate OpenAPI **3.1** from the same schema objects that serialize and validate — a
genuine kinship, and still rare in PHP. The differences are in delivery and enforcement: what
you get beyond the generated array, and whether anything proves the document true.

| Capability | This package | json-api-server |
| --- | --- | --- |
| OpenAPI 3.1 | **Yes** — every type, CRUD, relationship, related, and custom-action route, with Swagger UI / ReDoc viewer routes and a customization seam; see [openapi](openapi.md) | **Yes** — CRUD, relationship, related, action, and async paths from the same schema objects; no bundled viewer, and `rules()`-based validation is not reflected |
| Export & warmup | **Yes** — `jsonapi:openapi:export`, standalone per-type JSON Schema files, and `jsonapi:optimize` for deploys; see [optimize](optimize.md) | **No** — `generate()` returns a PHP array you serialize, serve, and cache yourself |
| Cross-framework contract | **Yes** — the Symfony and Laravel integrations emit a byte-identical document, enforced by a byte-compat CI job; see [openapi](openapi.md#byte-compatibility-with-the-symfony-bundle) | **No** — one package with one Laravel layer; there is no second integration to hold to a shared contract |
| Testing kit | **Yes** — document/error assertions, request builders, a `SchemaConformanceTrait` proving the document against real responses, and a runnable example app; see [testing](multi-server-and-testing.md#testing) and [workbench](workbench.md) | **No** — the (excellent) spec-mirroring suite and benchmarks are internal to the repo; no published assertions or example apps for consumers |

## Framework, runtime & authorization

This is the deliberate trade. json-api-server's Laravel integration is manual by design — a
PSR-7 bridge, a catch-all route, hand-registered resources — and in exchange it runs on any
PSR-7-capable stack with five tiny runtime dependencies. This package goes the other way:
Laravel-native from discovery to deploy.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Laravel integration | **Yes** — service provider auto-discovery, `#[AsJsonApiResource]` scanning, package config, `route:cache`-safe routes, artisan commands; see [routing](routing.md) and [configuration](configuration.md) | **Partial** — deliberately manual: a PSR bridge and a catch-all route closure; no service provider, config, route-caching integration, or artisan commands — though resource code itself feels Laravel-native |
| Long-running runtimes | **Yes** — a documented Octane / queue-worker posture with scoped bindings and warmed artifacts; see [optimize](optimize.md) | Not documented |
| Multi-server | **Yes** — servers side by side with per-server resources, config, routes, negotiation, and documents; see [multi-server](multi-server-and-testing.md#multi-server) | **Yes** — instantiate multiple `JsonApi` objects with different base paths, each with its own resources and document; routing is wired by hand |
| Authorization | **Yes** — declarative Gate policies per operation with natural ability names, per-relation security, per-object checks, and field predicates; see [authorization](authorization.md) | **Yes** — `visible()`/`hidden()`/`writable()` closures with a `can()` Gate helper, down to individual fields, filters, and sort fields; closure-based rather than policy classes |

## Where json-api-server shines

There is a lot to admire here, and some of it this package simply does not have:

- **Lean and framework-agnostic.** A PSR-15 handler with five tiny runtime dependencies —
  drop it into any PSR-7-capable stack with no bundle or provider machinery to adopt.
- **Boolean filter algebra.** Nestable `filter[and]`/`[or]`/`[not]` groups and per-filter
  operators go beyond a flat declarative vocabulary.
- **Top-level polymorphic listing.** A union SQL builder serves a *primary* collection of mixed
  models (a single listing endpoint returning albums + tracks + …). This package renders mixed
  types through polymorphic *relationships* (`MorphTo`/`MorphToMany`) but has no top-level
  heterogeneous collection.
- **The capability-interface design.** The storage abstraction and the permission surface are
  the same small contract — elegant and easy to reason about.
- **Craft.** A chapter-by-chapter spec test suite, benchmarks, a localizable error catalogue,
  disciplined changelogs, and the lineage of Flarum's author refining the API across nine
  release lines.

## Which should you choose?

**Choose json-api-server** if you value minimal dependencies and framework independence — a
non-Laravel PSR-7 stack, or a Laravel app where you would rather wire one route by hand than
adopt a package's conventions; or if boolean filter algebra or top-level heterogeneous
listing collections match your domain. It is a lean,
carefully crafted library approaching a well-earned 1.0.

**Choose this package** if you want the same spec-first, typed-schema philosophy with the
Laravel layer built out: zero-config [discovery and routing](routing.md), Gate
[policies](authorization.md), artisan tooling, a shipped
[testing kit](multi-server-and-testing.md#testing) with schema conformance, keyset
[cursor pagination](pagination.md#cursor-keyset-pagination) on every collection endpoint
including relationship linkage, [composite attributes](composite-attributes.md) with nested
error pointers, SQL push-down [windowing](eloquent.md#windowed-relationship-queries--sql-push-down-only)
for compound documents, and an OpenAPI pipeline that runs from
[viewer routes](openapi.md) through [deploy-time warmup](optimize.md) to a
[byte-identical contract](openapi.md#byte-compatibility-with-the-symfony-bundle) with the
Symfony bundle. json-api-server carries years of refinement and its author's production lineage,
while this package is newer — weigh that honestly against the breadth above.
