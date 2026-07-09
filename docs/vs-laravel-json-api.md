# Compared: LaravelJsonApi

An in-depth, dimension-by-dimension comparison of **`haddowg/json-api` (core) +
`haddowg/json-api-laravel`** against
**[LaravelJsonApi](https://laraveljsonapi.io)** ([`laravel-json-api/laravel`](https://github.com/laravel-json-api/laravel)).

All LaravelJsonApi claims below were checked against **v5.2.1** (released 2026-04-14,
supporting Laravel ^11–^13) and its published 5.x documentation and issue tracker. If you
are reading this much later, re-verify anything load-bearing — open issues get closed and
docs get written.

LaravelJsonApi is the established JSON:API package for Laravel: mature, widely adopted,
deeply idiomatic, with years of production track record. This suite is the newer entrant.
The honest summary is that the two projects have different centres of gravity:

- **LaravelJsonApi** implements JSON:API **1.0** with excellent Laravel ergonomics —
  Form Requests, policies, a Nova-inspired schema DSL, shipped translations in six
  locales, and first-class soft deletes.
- **This suite** implements JSON:API **1.1 in full** — extensions, profiles, atomic
  operations — on a framework-agnostic core, and treats the resource declaration as a
  *contract*: the same metadata that serves requests also projects a complete OpenAPI 3.1
  document, per-resource JSON Schema, and a typed TypeScript client.

## When LaravelJsonApi is the right choice

No point burying this. Choose LaravelJsonApi when:

- **Track record matters most.** It has years of production usage across a large install
  base; this suite is young and moving fast. That asymmetry is real.
- **You want shipped translations.** Its spec- and validation-error catalogues ship in
  English, Spanish, French, Italian, Dutch and Brazilian Portuguese. Ours ships an English
  catalogue plus a translator seam — you supply other locales yourself.
- **JSON:API 1.0 is enough** and you don't need OpenAPI, atomic operations, profiles, or a
  generated client. For a straightforward 1.0 CRUD API, it is a proven, well-documented
  choice.
- **You want Laravel Form Request validation verbatim.** Its validation layer *is*
  Laravel's — rules in request classes, `JsonApiRule` helpers, pointer-mapped 422s. Ours
  bridges declared constraints into `illuminate/validation` rules, which is idiomatic but
  a different authoring model.

## At a glance

| Dimension | This suite | LaravelJsonApi 5.2.1 | Verdict |
| --- | --- | --- | --- |
| [Document structure, CRUD & full spec coverage](#document-structure-crud--full-spec-coverage) | JSON:API 1.1 in full | JSON:API 1.0 only | **Advantage** |
| [Content negotiation](#content-negotiation-media-type-parameters-406415-handling) | 415/406 + `ext`/`profile` params | Strict 1.0 negotiation, no media-type params | **Advantage** |
| [Extensions mechanism (`ext=`)](#extensions-mechanism-ext-media-type-parameter) | Negotiated, Atomic bundled | Absent | **Advantage** |
| [Atomic operations](#atomicoperations-extension--parsing-execution--results) | Shipped, transactional, conformance-tested | Open request since 2021, unshipped | **Advantage** |
| [Profiles mechanism, registry & advertisement](#profiles-mechanism-registry--advertisement) | Full 1.1 profile machinery | Absent (1.0 has no profiles) | **Advantage** |
| [Cursor-pagination profile](#cursor-pagination-profile-ethan-resnick-spec) | Published profile, auto-advertised | Cursor package exists, not a profile | **Advantage** |
| [Author-published/custom profiles](#author-publishedcustom-profiles-countable-relationship-queries) | Countable, Relationship Queries bundled | Absent | **Advantage** |
| [Profile-contributed JSON Schema fragments](#profile-contributed-json-schema-fragments) | `allOf`-composed into validation | Absent | **Advantage** |
| [OpenAPI engine completeness](#openapi-engine-completeness--fidelity-paths-schemas-parameters-from-metadata) | Full OAS 3.1 projection, served + exported | None first-party | **Advantage** |
| [OpenAPI response declarations & vendor extensions](#openapi-response-declarations--vendor-extension-fidelity) | Per-operation responses, async lifecycle, self-describing pagination | None | **Advantage** |
| [Typed TypeScript client](#typed-typescript-client--query-cache-integration) | Sibling `json-api-ts` codegen + TanStack Query | None in org; no contract to generate from | **Advantage** |
| [Declared filter vocabulary & operators](#declared-filter-vocabulary--operators) | Rich vocabulary, metadata/execution split | Comparably rich, execution embedded | Different approach |
| [Filter value validation, defaults, fixed values & singular](#filter-value-validation-defaults-fixed-values--singular-collapse) | Declarative constraints + `default()`/`fixed()`/`singular()` | `singular()` + request-class rules; no default/fixed | **Advantage** (narrow) |
| [Author-composed filter groups](#author-composed-filter-groups-andornot-combinators) | `WhereAll`/`WhereAny`, nestable | Shipped in eloquent 4.3.0, undocumented in 5.x | Parity |
| [Relationship-path traversal filters](#relationship-path-traversal-filters-dotted-path-exists-semi-join) | `WhereThrough` dotted paths | Single-level relationship filters only | **Advantage** |
| [Multi-column sorting with defaults](#declaredcomputed-multi-column-sorting-with-defaults) | Declared/computed sorts, default sort | Sortables, custom sort classes, count sorts | Parity |
| [Pagination strategies offered](#pagination-strategies-offered-pageoffsetfixed-pagecursor) | Four strategies, one interface | Page + cursor (separate package) | Parity |
| [Client-selectable strategy menu (`page[kind]`)](#client-selectable-pagination-strategy-menu-pagekind) | Explicit selector, 400 menu, OpenAPI `oneOf` | Implicit inference only | **Advantage** |
| [Cursor pagination on included relations](#cursor-pagination-on-includedrelated-collections) | Batched includes, linkage endpoint, pivots | Unsupported (open request) | **Advantage** |
| [Page-size capping & cursor validation](#page-size-capping-counting-controls--cursor-id-validation) | Cap on by default, typed cursor errors | Manual validation rule; cursor-ID gap | **Advantage** |
| [Sparse fieldsets — output narrowing](#sparse-fieldsets-fieldstype-output-narrowing) | Full support | Full support | Parity |
| [Sparse fieldsets — SQL/computation push-down](#sparse-fieldsets--sqlcomputation-push-down) | `sparseByDefault()` skips computation | Output filtering only; `SELECT *` | **Advantage** |
| [Compound includes — safeguards & batching](#compound-documents--includes--safeguards--n1-safe-batching) | Depth caps, whitelists, SPI batching | Depth caps, guards, eager loading | Parity |
| [Error catalogue & typed exception model](#error-catalogue--typed-exception-model) | 53 typed exceptions, fixed shape | Error DTOs, no fixed catalogue | **Advantage** |
| [Stable machine-readable error codes](#stable-machine-readable-error-codes) | Every error carries a stable `code` | `code` empty by default, developer-assigned | **Advantage** |
| [Error message localization](#error-message-localization) | Code-keyed resolver, EN catalogue | Six locales shipped | Parity |
| [Request/response document schema validation](#requestresponse-document-schema-validation) | Vendored 1.1 JSON Schema, opt-in middleware | Laravel-rule validation | **Advantage** |
| [Per-resource JSON Schema publication](#per-resource-json-schema-publication-createupdate-fragments-export) | Compiled + exported per type | Unshipped roadmap item | **Advantage** |
| [Runtime value validation & rule bridges](#runtime-value-validation--framework-rule-bridges) | Schema-backed value validator + rule bridge | Laravel rules | **Advantage** (qualified) |
| [Framework-agnostic core](#framework-agnostic-core-psr-71517-zero-mandatory-coupling) | PSR-only core, runs standalone | Laravel HTTP stack required | **Advantage** |
| [Native Eloquent/Laravel integration depth](#native-eloquentlaravel-integration-depth) | Zero-config model mapping, policies, events | Deeply idiomatic, long track record | Parity |
| [Non-Eloquent / custom backend SPI](#non-eloquent--custom-backend-spi) | Storage-agnostic `DataProvider`/`DataPersister` | `non-eloquent` package, HTTP-bound | **Advantage** |
| [N+1 avoidance (linkage, counts, includes)](#n1-avoidance-for-relationship-linkage-counts--compound-includes) | Lazy linkage, batched counts | Native eager loading | Parity |
| [SQL push-down for windowed relations](#sql-push-down--windowing-for-paginated-included-or-nested-to-many-relations) | `ROW_NUMBER()` windowing, no PHP fallback | Not available | **Advantage** |
| [Testing utilities](#json-api-format-specific-test-assertions--helpers) | Assertions, builders, schema conformance | Official testing package, fluent builder | Parity |
| [Track record, adoption & governance](#production-track-record-adoption--release-cadencegovernance) | Young, rapid iteration | Mature, widely adopted, solo-maintained | **LaravelJsonApi** |
| [Soft deletes](#soft-deletes-recoverable-delete-restoreforce-delete-actions) | Explicit restore/force-delete actions | PATCH-based restore, trashed DELETE | Different approach |
| [Asynchronous processing (202/303)](#asynchronous-processing-202-accepted--303-see-other-job-lifecycle) | Shipped job lifecycle, in the contract | Unshipped roadmap item | **Advantage** |
| [Custom actions & capability composition](#custom-actions--capability-composition-without-a-full-resource) | Contract-declared actions, resource-less types | First-class routed actions, outside any contract | Different approach |
| [Multi-server support](#multi-server-support-independent-named-api-surfaces) | Named servers, per-server OpenAPI | Named servers, a founding concept | Parity |
| [Response headers (caching, deprecation)](#response-headers-caching-rfc-8594-deprecation) | Declarative cache + RFC 8594 headers | Ordinary middleware | **Advantage** (modest) |
| [Authorization model](#authorization-model-policypermission-integration) | Gate policies + relationship overrides | Policies + Authorizer classes | Parity |

---

## JSON:API 1.1 conformance

### Document structure, CRUD & full spec coverage

**This suite** implements the complete JSON:API **1.1** document model — the `jsonapi`
object, links, resource and identifier objects, compound documents, error objects — and
the full fetch/create/update/delete surface for resources *and* relationships, including
client-generated ids and `lid` local identifiers. Spec behaviour is pinned by a
[spec-compliance matrix](https://github.com/haddowg/json-api/blob/main/docs/spec-compliance.md)
with tagged tests. The Laravel package binds all of it through one invokable controller
and renders every failure on a JSON:API route as a spec-compliant error document (see
[errors](errors.md)).

**LaravelJsonApi** conforms to JSON:API **1.0** only. Its own
[compliance documentation](https://laraveljsonapi.io/5.x/requests/compliance.html) shows
error responses emitting `jsonapi.version: "1.0"`, and 1.1 additions are absent — for
example, [issue #316](https://github.com/laravel-json-api/laravel/issues/316) confirms
the 1.1 `source.header` error member is missing, with no linked work.

The 1.0 → 1.1 delta is not cosmetic: it is where `lid`, extensions, and profiles live —
the mechanisms that gate the next several sections.

### Content negotiation (media-type parameters, 406/415 handling)

**This suite** implements the 1.1 negotiation rules in core, including the asymmetry the
spec requires: an unsupported `Content-Type` parameter is a `415`, an unsatisfiable
`Accept` is a `406`, and the `ext`/`profile` media-type parameters are parsed and
negotiated. Strict query-parameter family validation is on by default (an unknown
`fields!`/`filter!`-family parameter is a clean `400`), wired through PSR-15 middleware in
core and mapped by the Laravel package's exception renderer.

**LaravelJsonApi** enforces `application/vnd.api+json` strictly — `406` on a bad `Accept`,
`415` on a bad `Content-Type`, both stated in its compliance docs — but this is 1.0-style
negotiation of the bare media type. Its compliance documentation makes no mention of the
`ext=` or `profile=` media-type parameters.

### Extensions mechanism (`ext=` media-type parameter)

**This suite** negotiates the `ext` media-type parameter strictly (an unsupported
extension is a `415` on request, `406` on `Accept`), with an empty default supported set.
The Atomic Operations extension is the one bundled implementation.

**LaravelJsonApi** has no `ext=` handling anywhere in its compliance documentation,
consistent with its 1.0-only conformance — extensions do not exist in JSON:API 1.0.

## Atomic operations (the `atomic:operations` extension)

### `atomic:operations` extension — parsing, execution & results

**This suite** ships the extension end to end. Core owns the framework-agnostic parts:
document parsing, ordered all-or-nothing execution, `lid` resolution *within* a batch,
`atomic:results` construction, and error-pointer prefixing by operation index so a failure
names the operation that caused it. The Laravel package wraps that loop in an opt-in
`POST /operations` endpoint that runs the whole batch inside **one database transaction**,
and proves the behaviour with a conformance suite that runs against both the Eloquent and
the in-memory provider. See [atomic-operations](atomic-operations.md).

**LaravelJsonApi** does not implement atomic operations as of 5.2.1. The oldest open
request is [issue #39](https://github.com/laravel-json-api/laravel/issues/39), filed in
February 2021 and still open. Its history is instructive about why: a working branch
existed in 2024 but was blocked on refactoring the package away from Laravel Form
Requests (the maintainer concluded "refactoring is a better option"), and in January 2026
the maintainer confirmed batch writes remain unshipped, recommending a hand-rolled custom
action instead — in his words, "I use `application/json` as the request type, i.e. saying
this does not follow the JSON:API spec."

If transactional multi-resource writes are on your requirements list, this is the single
sharpest difference between the two packages.

## Profiles (JSON:API 1.1 profiles)

### Profiles mechanism, registry & advertisement

**This suite** implements profiles as the spec defines them: URI-identified, advisory
extensions registered per server in a `ProfileRegistry`. Applied profiles are advertised
in the document's `jsonapi.profile` member, echoed as the `profile` media-type parameter
on `Content-Type`, and reflected in `Vary` — including the edge case where a profile is
activated by a *nested* render (a cursor-paginated included relation inside a page-based
primary document still advertises the cursor profile). See core
[profiles](https://github.com/haddowg/json-api/blob/main/docs/profiles.md).

**LaravelJsonApi** has no profile mechanism — its compliance docs discuss only the bare
`application/vnd.api+json` media type. Profiles are a 1.1 concept, so this follows
directly from its 1.0 scope.

### Cursor-pagination profile (Ethan Resnick spec)

**This suite** bundles the published
[cursor-pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/),
auto-activated whenever a cursor-based Page renders, so clients can detect keyset
pagination from the document itself.

**LaravelJsonApi** offers cursor pagination as functionality (the
`laravel-json-api/cursor-pagination` package) but not as a profile — its pagination docs
describe no profile advertisement, so a client cannot discover the semantics from the
response.

### Author-published/custom profiles (Countable, Relationship Queries)

**This suite** bundles two further profiles: **Countable** (opt-in per relation,
`withCount` → `meta.total` on the relationship) and **Relationship Queries** (filter and
sort a relationship's linkage from the primary request). The same `ProfileInterface` is
the extension point for publishing your own.

**LaravelJsonApi** has no equivalent, since it has no profile mechanism to hang one on.

### Profile-contributed JSON Schema fragments

**This suite**'s profiles can contribute draft-2020-12 JSON Schema fragments
(`SchemaContributingProfileInterface`) that are `allOf`-composed into the opt-in document
validator — a profile can both *add constraints* and *permit* its reserved top-level
members, so validation stays strict even with profiles active.

**LaravelJsonApi** has neither profiles nor JSON Schema validation (JSON Schema remains an
unshipped item on the maintainer's
[development plan, issue #238](https://github.com/laravel-json-api/laravel/issues/238)),
so this composition point does not exist.

## OpenAPI generation

### OpenAPI engine completeness & fidelity (paths, schemas, parameters from metadata)

**This suite** treats the resource declaration as a projectable contract. Core contains a
pure OpenAPI **3.1** projector that turns the same metadata that serves requests into a
complete document: every CRUD, relationship, related and custom-action path;
context-correct request/response schemas; pagination, filter, sort and include
parameters; reusable components. The Laravel package auto-generates and serves the
document (with a bundled Swagger UI / ReDoc viewer), exports it from the CLI, and — because
the Symfony bundle projects from the same core — CI enforces a **byte-identical** document
across both frameworks for an identical domain. See [openapi](openapi.md).

**LaravelJsonApi** has no first-party OpenAPI generation.
[Issue #19](https://github.com/laravel-json-api/laravel/issues/19) has been open since the
package's early days and remains unassigned — the maintainer's position is candid: "My
main problem is time to do it — if there are any contributors who want to work on it as a
project, let me know." The development plan (#238) explicitly orders OpenAPI *after* the
production-API feature list. Third-party generators exist (e.g.
[`swisnl/openapi-spec-generator`](https://github.com/swisnl/openapi-spec-generator)), but
they are unofficial and necessarily reverse-engineer the schema classes.

### OpenAPI response declarations & vendor-extension fidelity

**This suite**'s projection goes beyond happy-path schemas: per-operation success response
sets (`Created`/`Ok`/`NoContent`/`Accepted`/`SeeOther`/meta-result/action-resource)
including the full async `202` + `303` job lifecycle; vendor extensions such as
`x-enum-varnames`/`x-enum-descriptions` and `x-profile` on profile-gated parameters; and
self-describing `page[…]` parameter schemas — a Multi-paginator projects as a single
`oneOf` menu discriminated by its `kind`, so the generated document tells a client exactly
which pagination strategies are on offer.

**LaravelJsonApi** generates no OpenAPI at all, so none of this layer exists to compare.

## Typed TypeScript client

### Typed TypeScript client + query-cache integration

**This suite** has a sibling deliverable, [`json-api-ts`](https://github.com/haddowg/json-api-ts): a codegen CLI that consumes the
backend's OpenAPI 3.1 document and produces a typed client — typed reads and writes,
`?include`-hydrated result types, sparse-fieldset narrowing, atomic operations, custom
actions, opt-in per-field validation — plus first-class TanStack Query bindings with
`type:id` cache normalization. It is real, tested code with a published documentation
site and a [hosted demo application](https://haddowg.github.io/json-api-ts/spotify-clone/).
Two honest qualifications: it is a *sibling project* (the PHP
core deliberately keeps client-side concerns out of its own scope), and it is young —
claim it as an existing, working deliverable, not a battle-tested product.

**LaravelJsonApi** has no first-party TypeScript client or SDK generator anywhere in its
[organisation's repositories](https://github.com/orgs/laravel-json-api/repositories)
(all fifteen were checked), and — with no OpenAPI document — no machine-readable contract
from which to generate one.

## Filtering

### Declared filter vocabulary & operators

**This suite** declares filters as metadata-only value objects in core
(`FilterInterface`), executed by an Adapter (`FilterHandlerInterface`). Built-ins cover
`Where`, `WhereIn`/`WhereNotIn`, `WhereIdIn`/`WhereIdNotIn`, `WhereNull`/`WhereNotNull`,
`WhereHas`/`WhereDoesntHave`, `WhereThrough`, plus convenience filters (`Contains`,
`StartsWith`, `EndsWith`, `DateRange`, `Numeric`, `GreaterThan[OrEqual]`,
`LessThan[OrEqual]`, `Range`, `Boolean`). The Eloquent Adapter pushes all of them down to
SQL natively, with a seam for custom filter execution. The metadata/execution split is
not an aesthetic choice — it is what lets the same declarations feed the OpenAPI and JSON
Schema projections. See [core filters](https://github.com/haddowg/json-api/blob/main/docs/filters.md)
and [eloquent](eloquent.md).

**LaravelJsonApi** has a comparably rich built-in vocabulary
([docs](https://laraveljsonapi.io/5.x/schemas/filters.html)): `Has`, `Scope`, `Where`,
`WhereHas`/`WhereDoesntHave`, `WhereIdIn`/`WhereIdNotIn`, `WhereIn`/`WhereNotIn`,
`WhereNull`/`WhereNotNull`, pivot-specific `WherePivot`/`WherePivotIn`/`WherePivotNotIn`,
`WithTrashed`/`OnlyTrashed`, plus custom filter classes. Its filters embed their
execution directly (no metadata/contract split), and its pivot filters cover ground we
have not verified an exact equivalent for on our side.

**Verdict: different approach.** Both vocabularies are strong. Theirs is
execution-embedded and Eloquent-shaped; ours is contract-shaped, which pays off in the
OpenAPI/JSON Schema sections above and costs an extra concept (the Adapter).

### Filter value validation, defaults, fixed values & singular collapse

**This suite** validates filter values declaratively where the filter is declared:
`constrain()`, `numeric()`, `integer()`, `uuid()`, `boolean()`, `pattern()` render a
clean `400` before the request ever reaches the data layer. `default()` supplies a value
when the key is absent (client-overridable); `fixed()` pins the value so the key becomes
a pure presence trigger; `singular()` marks a filter whose match is zero-or-one. All of
this lives in core and is inherited by the Laravel package unchanged.

**LaravelJsonApi** supports singular filters (its docs: "Use the `singular` method to
mark a filter as a singular filter") and validates filter values via Laravel rules on
request classes — a working, idiomatic model. Its filter docs document no filter-level
default-value or fixed-value API.

**Verdict: a narrow advantage** — `default()`/`fixed()` and constraints colocated with
the declaration (which also makes them projectable into the contract). One caveat:
absence from their documentation is strong but not conclusive proof of absence from
their code.

### Author-composed filter groups (AND/OR/NOT combinators)

**This suite** ships `WhereAll` (AND) / `WhereAny` (OR) Filter groups: the author
composes child filters server-side under one named `filter[key]`, including fan-out
multi-column search, canned toggles via `fixed()` children, and arbitrary nesting. The
client sends one key — it cannot assemble arbitrary boolean algebra, by design.

**LaravelJsonApi** shipped `WhereAll`/`WhereAny` in its eloquent package **v4.3.0**
(October 2024 — verified in the
[eloquent CHANGELOG](https://github.com/laravel-json-api/eloquent/blob/develop/CHANGELOG.md)),
though they remain absent from the current 5.x filters documentation.

**Verdict: parity.** A real feature on both sides; theirs has a documentation lag, which
is worth knowing when you go looking for it.

### Relationship-path traversal filters (dotted-path EXISTS semi-join)

**This suite** ships `WhereThrough`, which walks a dotted relationship path
(`filter[author.publisher.country]=…`) as an EXISTS-ANY **semi-join** — never a
fetch-join, so no duplicated parent rows. `WhereHas`/`WhereDoesntHave` are its
single-hop special case. The Eloquent Adapter compiles it to a correlated `EXISTS`
subquery.

**LaravelJsonApi**'s filter docs document single-level relationship access only ("When
accessing a resource type via a relationship, you can use any of the filters that exist
of the resource type's schema") — no multi-hop dotted-path filtering. A related open
question about parameterizing relationship queries
([#319](https://github.com/laravel-json-api/laravel/issues/319)) has no visible
resolution.

## Sorting

### Declared/computed multi-column sorting with defaults

**This suite**: `->sortable()` on a field auto-derives a sort; `sorts()` declares
explicit or computed multi-column sorts with later-entries-win merging;
`defaultSort()` applies only when the request carries no explicit `?sort`; the
`SortHandlerInterface` receives the whole ordered directive list in one call, and the
Eloquent Adapter supports custom sorts appending to the composite `ORDER BY`.

**LaravelJsonApi** has solid support
([docs](https://laraveljsonapi.io/5.x/schemas/sorting.html)): sortable attributes,
default sort, and custom sort classes (`SortColumn`, `SortCountable`, `SortWithCount`)
including sorting by relationship count. Sorting by a related resource's attribute
(`sort=author.name`) is unsupported there
([#205](https://github.com/laravel-json-api/laravel/issues/205)) — but we have not
demonstrated that capability on our side either, so it differentiates neither package.

**Verdict: parity.**

## Pagination

### Pagination strategies offered (page/offset/fixed-page/cursor)

**This suite** ships four first-class Paginators — page-number, offset, fixed-page and
cursor — behind a single `PaginatorInterface` with a window/paginate contract that pushes
down to `LIMIT`/`OFFSET` or a keyset `WHERE`. A count-free mode
(`paginateWithoutCount()`) drives the `next` link from a has-more probe instead of a
`COUNT(*)`. See [pagination](pagination.md).

**LaravelJsonApi** offers page-based pagination (`page[number]`/`page[size]`) plus
cursor-based (`page[after]`/`page[before]`/`page[limit]`, via the separate
`cursor-pagination` package), combinable per schema.

**Verdict: parity** on strategies offered.

### Client-selectable pagination-strategy menu (`page[kind]`)

**This suite**'s Multi-paginator composes several strategies under one author-declared
menu. The client selects explicitly with `page[kind]=<kind>` (or implicitly via a
strategy-unique key); an unknown kind is a `400` whose `detail` lists the kinds actually
on offer; shared keys fall back to the declared default. The whole menu projects into
OpenAPI as one `page` deepObject parameter with a `oneOf` discriminated by `kind` — the
menu is self-documenting.

**LaravelJsonApi**'s `MultiPagination` also combines strategies per schema, but selection
is implicit — its docs state "the multi-pagination will use the page parameters supplied
by the client to decide which paginator to use." There is no explicit selector, no `400`
listing the menu on a bad choice, and no self-documentation of what is on offer.

### Cursor pagination on included/related collections

**This suite** renders cursor pagination on **batched includes** (an included to-many
relation is cursor-paginated per parent via keyset push-down — an included relation's
cursor page is always a first page, with an id tie-break and a has-more probe), on the
**relationship (linkage) endpoint**, and **over a pivot join** (pivot members still
render on a cursor page).

**LaravelJsonApi** does not support paginating relationships nested inside compound
documents — [issue #177](https://github.com/laravel-json-api/laravel/issues/177) is an
open feature request with no linked work, and the pagination docs describe no
included-resource pagination.

### Page-size capping, counting controls & cursor-ID validation

**This suite** caps client-controlled page sizes at **100 by default** —
`page[size]=1000000` yields 100 items and a `200 OK`, no configuration required.
Counting is opt-in (`withCount()` or the Countable profile), so a paginated collection
does not silently pay for a `COUNT(*)`. Cursor tokens are validated and decoded by
core's `CursorCodec`, with dedicated `CursorMalformed`/`CursorStale` error kinds.

**LaravelJsonApi** leaves the maximum page size to developer-configured validation (its
docs recommend a manual `between:1,100` rule), and cursor pagination lacks a first-party
validation rule for encoded ids —
[issue #237](https://github.com/laravel-json-api/laravel/issues/237) shows standard rules
like `exists:lessons,id` failing against encoded cursor values.

## Sparse fieldsets & includes

### Sparse fieldsets (`fields[type]` output narrowing)

Both packages support `fields[TYPE]` narrowing of attributes and relationships well —
ours with an `id` exemption and a `notSparseField()` opt-out, theirs including validation
of requested field names via `JsonApiRule::fieldSets()`. **Parity.**

### Sparse fieldsets — SQL/computation push-down

**This suite** goes a step further than output narrowing: a field marked
`sparseByDefault()` is opt-in-only — present only when explicitly named in
`fields[type]` — and its computation is **skipped entirely** when omitted. That makes an
expensive derived attribute (an aggregate, a remote call) free on every request that
doesn't ask for it. See [resources](resources.md).

**LaravelJsonApi**'s sparse fieldsets are output filtering only:
[issue #146](https://github.com/laravel-json-api/laravel/issues/146) (open, enhancement,
no linked PRs) confirms the database still runs `SELECT *` regardless of the `fields[]`
requested.

### Compound documents / includes — safeguards & N+1-safe batching

**This suite** builds compound documents with dedup and primary-takes-precedence in core,
supports default-included relations, and layers composable safeguards:
`cannotBeIncluded()`, a max include depth, and an allowed-paths whitelist. The Laravel
package batches include loading through a provider-agnostic SPI built on Eloquent's
eager-load internals.

**LaravelJsonApi** handles the same ground well: include-path validation
(`JsonApiRule::includePaths()`), defaults, `maxDepth`, eager-load guards
(`cannotEagerLoad()`/`canEagerLoad()`), and first-class N+1 avoidance via native Eloquent
eager loading.

**Verdict: parity** on this dimension taken alone — the differentiation lives in the
adjacent push-down and windowed-relation rows.

## Error handling

### Error catalogue & typed exception model

**This suite**'s core ships a fixed **error catalogue**: 53 concrete typed exception
classes, each implementing `JsonApiExceptionInterface` and fixing its own HTTP status,
`code`, `title`, `detail` and `source`. You throw the exception; the document renders
itself; an uncaught throwable renders a debug-gated `500`. The Laravel package maps all
of it through a route-scoped exception renderable plus tagged custom exception mappers,
so JSON:API routes render error documents and the rest of your app is untouched. See
[errors](errors.md).

**LaravelJsonApi**'s errors are spec-compliant DTOs with automatic mapping of validation
errors to `source.pointer` — solid — but there is no fixed exception-per-failure
catalogue, and the 1.1 `source.header` member is unsupported
([#316](https://github.com/laravel-json-api/laravel/issues/316)).

### Stable machine-readable error codes

**This suite**: every error carries a stable `SCREAMING_SNAKE` `code` member
(`RESOURCE_NOT_FOUND`, `FULL_REPLACEMENT_PROHIBITED`, …) independent of the human copy.
The localization resolver can rewrite `title`/`detail`; it can never touch `code` or
`status`. A client can branch on `code` and survive every copy change.

**LaravelJsonApi**: verified directly in its spec package's shipped English catalogue
(`lang/en/spec.php`), every compliance error ships `'code' => ''` — the file's own
comment says the member is populated only "if you want to give the error a specific
code". Codes are developer-assigned, not a shipped, stable catalogue.

### Error message localization

**This suite** resolves `title`/`detail` templates per error `code` through an
`ErrorMessageResolverInterface` with `{placeholder}` interpolation and graceful fallback
to the built-in English catalogue; the Laravel package routes this through Laravel's
translator (the `jsonapi-errors` group), so localizing copy is a lang file away — and can
never change a `code` or a status.

**LaravelJsonApi** localizes genuinely well: publishable translation files for
spec-compliance and validation errors, with **English, Spanish, French, Italian, Dutch
and Brazilian Portuguese all shipped** in the `spec` and `validation` repositories.
Translatable members are `title`, `detail` and `code`.

**Verdict: parity, honestly.** They ship six locales out of the box; we ship one plus a
resolver seam. Our mechanism is architecturally cleaner (code-keyed, with `code`/`status`
immutable under translation), but if you need Spanish error copy today, theirs is ahead.

## Validation / JSON Schema

### Request/response document schema validation

**This suite** offers an optional `DocumentValidator` (backed by `opis/json-schema`,
a dev-suggested dependency, never forced on you) that validates decoded documents against
**vendored JSON:API 1.1 draft-2020-12 schemas**, with separate request and response roots
and profile-fragment extension, wired via two opt-in PSR-15 middleware — typically enabled
in CI and staging as a structural conformance witness. See
[core schema validation](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md).

**LaravelJsonApi**'s validation is mature and idiomatic — rules in request classes and
schema field classes, `JsonApiRule` helpers, pointer-mapped `422`s — but it is Laravel
*rule* validation of member values, not JSON Schema validation of document structure
against the spec's own schema.

### Per-resource JSON Schema publication (create/update fragments, export)

**This suite**'s core compiles each type's field-and-constraint metadata into
per-type create/update draft-2020-12 fragments; the Laravel package exports standalone
per-type JSON Schema documents via an Artisan command and bridges the same declared
constraints into real `illuminate/validation` rules with localizable messages — one
declaration, three consumers (runtime validation, JSON Schema, OpenAPI). See
[validation](validation.md).

**LaravelJsonApi** lists JSON Schema for validation as a planned item on the maintainer's
development plan ([#238](https://github.com/laravel-json-api/laravel/issues/238)) — it has
not shipped, and no schema-publication capability appears in docs or changelogs.

### Runtime value validation & framework rule bridges

**This suite** also ships a schema-backed runtime value validator in core
(`SchemaValueValidator` — a single value checked against an opis-compiled schema derived
from a constraint's self-description), shared by the filter-value and field validation
paths; composite attribute types (`Obj`/`OneOf`/`Shape`) validate on the Laravel side
too (see [composite-attributes](composite-attributes.md)).

**LaravelJsonApi** expresses all validation as Laravel rules; we found no equivalent
schema-backed value validator, though we did not exhaustively search its codebase for
one — treat this as a qualified advantage.

## Framework fit

The framing that matters here: this suite is built so the same resource model is
**idiomatic in whatever stack you have already chosen**. Core is a pure-PHP JSON:API
engine; this package makes it feel native to Laravel and Eloquent; a sibling bundle makes
it feel native to Symfony and Doctrine; the SPI makes a custom backend a first-class
citizen. LaravelJsonApi is idiomatic in exactly one place — which is fine if that place
is yours, and a hard stop if it isn't.

### Framework-agnostic core (PSR-7/15/17, zero mandatory coupling)

**This suite**'s core requires PHP `^8.3` plus PSR interfaces only (`psr/container`,
`psr/http-factory`, `psr/http-message`, `psr/http-server-handler`,
`psr/http-server-middleware`, `psr/log`) — verified directly in its `composer.json`. A
bare `Server` plus a PSR-15 handler is fully functional standalone: you can execute
Operations programmatically, no HTTP stack required. The Symfony bundle
([`haddowg/json-api-symfony`](https://github.com/haddowg/json-api-symfony)) is the
existence proof that the same core serves a second framework natively.

**LaravelJsonApi** has no framework-agnostic core. Invoking it outside an HTTP request
cycle has been an open enhancement request since December 2021
([#144](https://github.com/laravel-json-api/laravel/issues/144), no linked work), and
"programmatic API consumption without HTTP requests" is an unshipped item on the
development plan (#238).

### Native Eloquent/Laravel integration depth

**This suite**: zero-config three-tier type-to-model resolution auto-registers a
reference Eloquent DataProvider/DataPersister pair; Gate policies, real Laravel events,
Artisan tooling, `route:cache` safety, and native polymorphic to-many (`MorphToMany`,
`morphedByMany` over one pivot). See [eloquent](eloquent.md).

**LaravelJsonApi** is deeply idiomatic to Laravel and Eloquent — a Nova-inspired schema
DSL, Form Requests, policies — with a long production track record, and its filter set
includes pivot-specific filters we have not matched feature-for-feature.

**Verdict: parity.** Both feel native in Laravel. Do not read our polymorphic to-many
support as an advantage over them — we have no evidence they lack it.

### Non-Eloquent / custom backend SPI

**This suite** exposes a full storage-agnostic SPI — `DataProvider`/`DataPersister` — so
any backend (an HTTP service, a document store, a legacy DAO layer) plugs in with the
whole feature surface intact; an in-memory reference provider ships and doubles as the
conformance witness for the Eloquent provider. See
[custom-data-providers](custom-data-providers.md). And because the core is
framework-agnostic, the Doctrine story is not a port — it is the sibling Symfony bundle
sharing the identical engine.

**LaravelJsonApi** has a `non-eloquent` package supporting plain PHP classes, but it
still runs inside the Laravel HTTP stack; there is no Symfony/Doctrine counterpart.

## Performance

### N+1 avoidance for relationship linkage, counts & compound includes

**This suite**: linkage is lazy by default — serializing a relationship's ids never
forces a load the query didn't already do (`RelationshipLoadStateInterface`); relationship
counts are batched across a fetched page of parents (`RelationCountBatcher`); compound
includes batch through the SPI described above.

**LaravelJsonApi** treats N+1 avoidance for compound documents as a first-class concern
via native Eloquent eager loading with depth caps and guards.

**Verdict: parity.**

### SQL push-down / windowing for paginated included or nested to-many relations

**This suite**: per-parent relationship windowing is **SQL-push-down only** — Eloquent's
`groupLimit()` / `ROW_NUMBER() OVER (PARTITION BY …)` with an id tie-break, and
deliberately *no* PHP-side windowing fallback (a strategy that cannot push down fails
loudly rather than silently loading everything). The behaviour is refereed in CI against
the in-memory witness provider. See [optimize](optimize.md) and
[relationships](relationships.md).

**LaravelJsonApi** has no windowed/limited SQL pagination of included to-many relations
(open request [#177](https://github.com/laravel-json-api/laravel/issues/177), docs
silent) and no sparse-fieldset push-down (`SELECT *` confirmed via
[#146](https://github.com/laravel-json-api/laravel/issues/146)). For wide rows and large
to-many relations, this is the row with direct query-cost consequences.

## Testing utilities

### JSON:API-format-specific test assertions & helpers

**This suite**: core ships (in the runtime autoload, usable from any consumer suite)
fluent `JsonApiDocument`/`JsonApiErrors` assertions,
`JsonApiRequestBuilder`/`JsonApiOperationBuilder` builders, and one-line spec-compliance
schema assertions. The Laravel package adds the `InteractsWithJsonApi` trait,
JSON:API-aware `TestResponse` macros, and an opt-in `SchemaConformanceTrait` that
validates real responses against the **generated OpenAPI schemas** — your tests referee
your contract. The package's own conformance suite (48 files) runs dual-provider,
Eloquent and in-memory. See [multi-server-and-testing](multi-server-and-testing.md).

**LaravelJsonApi** has an official `testing` package: a `MakesJsonApiRequests` trait and
a fluent `jsonApi()` request/assertion builder on Laravel's `TestResponse`, with
human-readable, sorted-key, pretty-printed JSON diffs on failure — genuinely good
ergonomics with a long community track record.

**Verdict: parity.** Both kits are mature; ours adds the OpenAPI-conformance witness,
theirs has years of real-world polish.

## Maturity, adoption & production track record

### Production track record, adoption & release cadence/governance

**LaravelJsonApi** is the mature, widely adopted package: v5.2.1, years of production
usage, supporting Laravel ^11–^13. Two things worth weighing alongside that. First, its
release cadence is now mostly Laravel-version-support bumps rather than feature work.
Second, it is solo-maintained, and the maintainer has repeatedly and openly attributed
the roadmap gaps documented above — atomic operations, OpenAPI, JSON Schema, non-HTTP
execution — to limited open-source time (stated plainly in
[#19](https://github.com/laravel-json-api/laravel/issues/19) and
[#238](https://github.com/laravel-json-api/laravel/issues/238)). That is not a criticism
— it is the ordinary economics of solo open source — but it means the feature gaps in
this comparison have been stable for years and have no announced delivery dates.

**This suite** is the opposite profile: two actively co-developed sibling packages (core
+ this adapter) with dense recent feature velocity, but a short public history and no
comparable install base. We cannot claim years of production hardening, and we won't.
What we can claim is the conformance apparatus built in from the start: a dual-provider
test suite, a spec-compliance matrix, and a CI-enforced byte-identical OpenAPI contract
against the sibling Symfony bundle.

**Verdict: LaravelJsonApi's row.** If maturity dominates your decision, it wins this
dimension outright.

## Soft deletes

### Soft deletes (recoverable delete, restore/force-delete actions)

Both packages support soft deletes well; the designs differ meaningfully.

**This suite** (`#[AsJsonApiResource(softDeletes: true)]`): `DELETE` stays a
*recoverable* soft delete; **restore** (`200`) and **force-delete** (`204`) are
synthesized, discoverable [custom actions](actions.md) — toggleable and renamable —
dispatching to the model policy's native `restore()`/`forceDelete()` methods, so
recovering and destroying are **distinctly authorized** operations. Permanent removal is
only ever reachable through the explicit action. `WithTrashed`/`OnlyTrashed` filters
surface trashed rows. See [soft-deletes](soft-deletes.md).

**LaravelJsonApi** ([docs](https://laraveljsonapi.io/5.x/schemas/soft-deleting.html)):
`DELETE` soft-deletes by default; restore is a `PATCH` setting the soft-delete attribute
(`deletedAt: null`); force delete is a `DELETE` of an already-trashed resource;
`WithTrashed`/`OnlyTrashed` filters ship and Eloquent restore/forceDelete events fire.
Its docs describe no distinct restore/forceDelete policy authorization.

**Verdict: different approach.** Theirs models restore as attribute mutation (fewer
endpoints, plain PATCH semantics); ours models it as explicit, separately-authorized
operations (a client can never permanently destroy data with the verb it uses every
day). Pick the semantics you want your API to have.

## Asynchronous processing (202/303 job lifecycle)

### Asynchronous processing (202 Accepted / 303 See Other job lifecycle)

**This suite**: a DataPersister can return `AcceptedForProcessing` from
`create()`/`update()` to render a spec-correct `202 Accepted` with
`Content-Location`/`Retry-After` pointing at a pollable job resource; the job resource
answers `303 See Other` on completion. The whole lifecycle is **declared in the OpenAPI
contract**, and async writes are rejected inside an atomic batch (an all-or-nothing
guarantee cannot contain a maybe-later). See [async writes](async.md).

**LaravelJsonApi**: "async processing support" is a planned, unshipped item on the
development plan ([#238](https://github.com/laravel-json-api/laravel/issues/238)), and no
async job lifecycle appears in the current package's docs. One fairness note: the legacy
`cloudcreativity/laravel-json-api` predecessor did support async, so the pattern is known
in that ecosystem — it just has not been carried into the current package as of 5.2.1.

## Custom actions & capability composition

### Custom actions & capability composition without a full resource

**This suite**: non-CRUD `-actions/{name}` endpoints, resource- or collection-scoped,
with Document/Raw/None input modes, ability gating, **state-aware discoverable links**
(an action advertises itself on the resources it currently applies to), and per-action
OpenAPI response declarations — actions are part of the contract, not beside it.
Separately, the capability model composes: serializer, hydrator, provider and persister
are independent capabilities, so a resource-less type or a "two types, one model" design
needs no `AbstractResource`, and a write operation without a hydrator fails discovery
rather than failing at runtime. See [actions](actions.md) and
[capability-composition](capability-composition.md).

**LaravelJsonApi** has first-class custom actions too
([docs](https://laraveljsonapi.io/5.x/routing/custom-actions.html)): registered via
`actions()` in route registration, any HTTP verb, resource-level or per-instance
(`withId()`), controller-method mapping, query-parameter class injection, and policy
authorization — and the maintainer actively recommends them (it is his suggested
workaround for batch writes). They are, however, conventional controller code outside
any generated contract: no OpenAPI declaration (there is no OpenAPI), no discoverable
state-aware links, and no documented resource-less capability composition.

**Verdict: different approach.** Theirs is looser and very Laravel; ours trades a little
ceremony for actions that appear in the contract and advertise themselves to clients.

## Multi-server support

### Multi-server support (independent named API surfaces)

**This suite**: independent named servers, each with its own URI prefix, middleware and
optional domain; per-resource server membership; server-scoped route names; and separate
or combined **OpenAPI documents per server**. See
[multi-server-and-testing](multi-server-and-testing.md).

**LaravelJsonApi**: multiple named servers per application is an explicit core concept
from day one — each server has its own URI namespace and resource set.

**Verdict: parity** on the mechanism (the per-server OpenAPI document is ours alone, but
that is the OpenAPI row's advantage, not this one's).

## Response headers (caching, deprecation)

### Response headers (caching, RFC 8594 deprecation)

**This suite**: declarative per-resource `Cache-Control`/`Vary` headers (with
per-read-shape overrides) on successful `GET`s, plus RFC 8594
`Deprecation`/`Sunset`/sunset-link headers, layered over global config defaults — the
deprecation posture of a resource lives on the resource.

**LaravelJsonApi**: no declarative package-level cache-header or deprecation-header
support appears in its docs. The practical gap is modest — ordinary Laravel middleware
can set the same headers — so read this as a convenience-and-colocation advantage, not a
capability wall.

## Security & authorization posture

### Authorization model (policy/permission integration)

**This suite**: native Gate policy authorization (`viewAny`/`view`/`create`/`update`/
`delete`), ability-name overrides, an optional dedicated API-policy class, and
relationship-level control — relationship mutations default to the parent's `update`
ability but are overridable per relation, and related/relationship *reads* are gateable
per relation. See [authorization](authorization.md).

**LaravelJsonApi**: comprehensive and verified — standard Laravel policies,
relationship-level policy methods (`viewAuthor`, `updateAuthor`, `attachTags`/
`detachTags` with `LazyRelation` inspection), custom `Authorizer` classes (per-resource,
per-group, or server-wide), and Gate responses with custom error messages.

**Verdict: parity.** Both are thorough, idiomatic Laravel authorization.

---

## Summary

Choose **LaravelJsonApi** for its maturity, its install base, its six shipped error
locales, and a proven JSON:API 1.0 implementation that is deeply comfortable for a
Laravel team.

Choose **this suite** when the things it uniquely ships are on your requirements list:
full JSON:API **1.1** (extensions, profiles, `lid`), **atomic operations**, a complete
generated **OpenAPI 3.1 contract** with a typed **TypeScript client** behind it,
**JSON Schema** publication and document validation, **SQL push-down** for sparse fields
and windowed relations, a **stable machine-readable error catalogue**, and a
framework-agnostic core that is equally idiomatic in Laravel, in Symfony, or over a
custom backend.

The gaps on their side are not oversights — the maintainer has documented them as
roadmap items constrained by solo maintenance time, and they have been open for years
(atomic operations since 2021, OpenAPI since the package's early days, non-HTTP
execution since 2021). The gap on our side is exactly one dimension — track record — and
only time closes it.
