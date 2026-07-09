# vs json-api-server

[`tobyz/json-api-server`](https://github.com/tobyzerner/json-api-server) — by Toby Zerner,
the creator of Flarum — is the closest philosophical relative this package has. Both are
spec-first, PSR-7/PSR-15 designs where one set of declared schema objects drives
serialization, validation, and OpenAPI 3.1; both implement atomic operations and the
published cursor-pagination profile; and both treat "expose only what you declared" as a
design principle rather than an afterthought. The differences are mostly differences of
investment: json-api-server stays deliberately lean (a PSR-15 handler you wire by hand,
with a first-party Laravel binding), while this stack invests in a deep Laravel-native
layer — discovery, routing, artisan tooling, Gate policies, a testing kit — plus a
CI-enforced byte-identical contract with its Symfony twin, so the library is idiomatic in
whichever stack you already chose.

It is not one-sided. json-api-server ships things this stack does not: a ready-made
relationship-count sort primitive, a first-class heterogeneous `Collection` construct for
serving multiple resource types from one path, a named `linkageMeta()` primitive for
pivot-style meta on linkage objects, and (on its unreleased `main`) a generic
per-operator filter syntax. Those are conceded plainly below.

**Version note.** Comparison as of 9 July 2026, against **v1.0.0-rc.1** — json-api-server's
latest tagged release (published 2026-01-01 and flagged by GitHub as a prerelease). No
stable 1.0.0 has ever been cut; the tag history runs 0.1.0-beta.1 → 1.0.0-alpha.1/2 →
beta.1–6 → rc.1. The `main` branch is further ahead, with unreleased work (typed filters,
per-operator filtering, `Attachable` support) not in any tag — where a capability is
`main`-only or landed in a specific pre-1.0 tag, the tables say so. Found an error?
[Open an issue](https://github.com/haddowg/json-api-laravel/issues).

## JSON:API 1.1 conformance

Both projects are serious about the spec, and it is worth crediting: json-api-server's
README and docs site claim full conformance across CRUD, sorting, filtering, pagination,
sparse fieldsets, includes, content negotiation, errors, extensions, and profiles. The
differences are in explicitness — which spec version is targeted, and how precisely the
negotiation edge cases are pinned down.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Document structure, CRUD & full spec coverage | Yes — explicitly targets **JSON:API 1.1**: full document structure (`jsonapi` object, links, resource/identifier objects, compound documents, errors), fetch/create/update/delete for resources *and* relationships, client-generated ids, and `lid`, tracked row-by-row in a [public spec-compliance table](https://github.com/haddowg/json-api/blob/main/docs/spec-compliance.md) with zero unchecked rows | Partial — claims full spec conformance, but the README links only the unversioned spec and no page states whether 1.0 or 1.1 is targeted |
| Content negotiation (media-type parameters, 406/415 handling) | Yes — `Content-Type`/`Accept` media-type parameter rules with a documented 415-vs-406 asymmetry, `ext`/`profile` parsing, and strict query-parameter family validation on by default, wired via PSR-15 middleware | Partial — the PSR-15 handler auto-negotiates the media type to activate extensions and profiles, but the requests documentation covers only basic PSR-7 handling, error catching, and auth patterns; no 415/406 rules, media-type parameter mechanics, or error precedence are documented |
| Extensions mechanism (`ext=` media-type parameter) | Yes — `ext` negotiated strictly (415/406 on unsupported), default supported set is empty, and only the Atomic Operations extension ships out of the box | Partial — extensions are simple `uri()`/`handle()` classes, carrying an explicit maintainer warning: *"The current implementation of extensions has no support for augmentation of standard API responses. This API may change dramatically in the future."* |

## Atomic operations

Both implement the `atomic:operations` extension, including `lid` resolution within a
batch and per-operation error pointers. The decisive difference is atomicity itself:
json-api-server's docs state verbatim that you are *"responsible for wrapping the
`$api->handle` call in a transaction to ensure any database (or other) operations
performed are actually atomic in nature"* — the extension parses and executes the batch,
but nothing guarantees all-or-nothing behaviour unless you build it.

| Capability | This package | json-api-server |
| --- | --- | --- |
| `atomic:operations` — parsing, execution & results | Yes — framework-agnostic parser, ordered all-or-nothing execution loop, and a backend seam: `lid` resolution within a batch, `atomic:results` (empty results as `{}`), error pointers prefixed by operation index, and extension advertisement, all test-proven in the [spec-compliance table](https://github.com/haddowg/json-api/blob/main/docs/spec-compliance.md) | Yes — a built-in Atomic extension registered via `$api->extension()`; the library never wraps operations in a DB transaction itself, and as of October 2025 atomic operations were not yet reflected in the generated OpenAPI output (maintainer, issue #62) |
| Transactional guarantee (Eloquent backend) | Yes — the opt-in `POST /operations` endpoint runs add/update/remove batches all-or-nothing: a transaction is opened on every participating persister's connection, all committed together or all rolled back on any sub-operation failure (a non-transactional persister is refused), with `lid` resolution across the batch and `After*` events/hooks deferred until after commit — a rolled-back batch fires no `After*` hooks; see [atomic operations](atomic-operations.md) | No — by explicit design, the consumer wraps the handler call in their own transaction |

## Profiles

Genuine parity on the mechanism, and worth stating plainly: json-api-server shipped full
profile support in the rc.1 prerelease — `Accept`-header profile detection via
`Context::profileRequested`, `activateProfile()`, and the profile echoed in the response
`Content-Type` — with automatic cursor-pagination activation as its one built-in. The
differences are in what ships *on top of* the mechanism.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Profiles mechanism, registry & advertisement | Yes — URI-identified, advisory `ProfileInterface` (`uri()`/`keywords()`/`finalizeDocument()`) with a per-server `ProfileRegistry`; applied profiles are advertised in `jsonapi.profile`, the `Content-Type` `profile` parameter, and `Vary: Accept` | Yes — `Accept`-header profile URI detection, `activateProfile()`, and `Content-Type` echo, shipped in the v1.0.0-rc.1 prerelease (no stable tag carries it) |
| Cursor-pagination profile (Ethan Resnick spec) | Yes — `CursorPaginationProfile` advertises the published profile URI; a cursor Page activates it automatically when the profile is registered, including when a cursor page is nested inside a page-based primary document | Yes — cursor pagination (`page[size]`/`page[after]`/`page[before]`) shipped in beta.6 (2025-10-02) explicitly following the same published profile; rc.1 added automatic activation. Parity |
| Author-published/custom profiles (Countable, Relationship Queries) | Yes — two additional published profiles ship with their own spec documents: **Countable** (`?withCount=_self_,rel` returns `meta.total` for a countable primary collection and/or named relations, opt-in per relation) and **Relationship Queries** (`relatedQuery[path][…]`/`rQ` shorthand to filter/sort a relationship's linkage from the primary request) | No — the docs describe only the custom-profile mechanism; cursor-pagination is the only built-in named anywhere |
| Profile-contributed JSON Schema fragments | Yes — `SchemaContributingProfileInterface` lets a profile add a draft-2020-12 fragment composed via `allOf` into the opt-in document validator, both adding constraints and permitting profile-reserved top-level members | Not found — their profiles documentation mentions nothing equivalent, and no document-level validator exists to extend (see [Validation](#validation--json-schema) below); absence from docs is not proof of absence in code |

## OpenAPI generation

Both generate OpenAPI 3.1, and json-api-server's generator deserves credit — reworked for
rc.1 and assessed by its own maintainer (issue #62, October 2025) as producing
"comprehensive and valid OAS 3.1 docs". The maintainer notes in the same thread that
atomic operations are still missing from the generated spec, and that shipping export
tooling is deliberately out of scope: *"I'm keen for the lib to be unopinionated about
this and just provide the `OpenApiGenerator` class."* This stack takes the opposite bet:
the OpenAPI document is the product's contract, so projection fidelity, export tooling,
and cross-framework identity are all first-class.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Engine completeness & fidelity | Yes — a pure, framework-agnostic OAS 3.1 projector turns the metadata contract into a full document: CRUD/relationship/related/custom-action paths, context-correct create/update/read schemas, id policy, pagination/filter/sort/include parameters, reusable component schemas; see [OpenAPI](openapi.md) | Partial — comprehensive, valid OAS 3.1 per the maintainer's own assessment, but atomic operations are absent from the generated spec |
| Response declarations & vendor-extension fidelity | Yes — per-operation success response sets (`Created`/`Ok`/`NoContent`/`Accepted`/`SeeOther`/`MetaResult`/`ActionResource`) let a resource or action declare a 204 or an async 202+303 job lifecycle in the contract; `x-enum-varnames`/`x-enum-descriptions` for backed enums, `x-profile` marking profile-gated parameters, documented lossy-degradation notes for constraints with no JSON Schema analogue, and self-described paginator `page[…]` schemas including a Multi-paginator `oneOf` menu | Unknown — the async 202/303 lifecycle is a documented runtime feature, but no source confirms the generated OpenAPI reflects those response shapes, and no vendor-extension fidelity markers or lossy-degradation notes were found |
| Cross-framework parity (CI-enforced byte-identical output) | Yes — this package's auto-generated document is byte-identical to the sibling Symfony bundle's for an identical domain, CI-enforced via `composer byte-compat` (covering both the OpenAPI document and the exported JSON Schemas) | No — a consequence of scope rather than a defect: Laravel is the only framework binding, so there is no second implementation to hold identical, and no equivalent contract-identity guarantee is documented |
| Export tooling (CLI) | Yes — `jsonapi:openapi:export` artisan command, HTTP-served Swagger UI / ReDoc, and a `jsonapi:schemas` export for standalone JSON Schemas | No — deliberately unopinionated; the library provides the `OpenApiGenerator` class only |

## Typed TypeScript client

Neither of the two packages compared here ships client-side code itself; the difference
is what exists one step away. This stack's byte-stable OpenAPI contract has a sibling
consumer built specifically for it —
[`json-api-ts`](https://github.com/haddowg/json-api-ts), a codegen CLI producing a typed,
JSON:API-aware client plus TanStack Query bindings with `type:id` cache normalization,
with a published documentation site and a
[hosted demo application](https://haddowg.github.io/json-api-ts/spotify-clone/). Qualify
it honestly: it is young, and a separate project rather than part of this repo. json-api-server has no client generator in its
orbit (the maintainer's separate `json-api-models` project is a hand-authored client, not
codegen), though its generated OpenAPI document could feed generic OAS tooling.

## Filtering

The deepest philosophical split in the comparison. json-api-server (beta.5, 2025-09-27)
gives the *client* boolean composition: `filter[and]`/`filter[or]`/`filter[not]`, opt-in
per resource via `SupportsBooleanFilters` — including a NOT primitive this stack does not
name. This stack deliberately holds filtering to an author-composed allow-list: every
filter is a named, declared query shape, and `WhereAll`/`WhereAny` filter groups compose
AND/OR *server-side* under one `filter[key]` — the client cannot invent boolean algebra.
That is a trade, not a missing feature: it keeps query cost bounded to shapes the author
vetted and keeps the filter surface exporting to OpenAPI as discrete, documented
parameters. But read it as the philosophy split it is.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Declared filter vocabulary & operators | Yes — Filters are metadata-only value objects (`FilterInterface`); execution lives in an Adapter (`FilterHandlerInterface`). Core ships a reference in-memory handler plus 10 built-in filter types (`Where`, `WhereIn`/`NotIn`, `WhereIdIn`/`NotIn`, `WhereNull`/`NotNull`, `WhereHas`/`DoesntHave`, `WhereThrough`) and 11 convenience filters (`Contains`, `StartsWith`, `EndsWith`, `Numeric`, `GreaterThan[OrEqual]`, `LessThan[OrEqual]`, `Boolean`, `Range`, `DateRange`); this package pushes them down natively to the Eloquent query builder | Partial — named per-resource filters (inline `CustomFilter` or `Filter` subclasses) are stable and released, and the Laravel binding adds Eloquent filter classes (`Where`, `WhereBelongsTo`, `WhereExists`, `WhereCount`, `WhereHas`, `WhereNull`, `WhereNotNull`, `Scope`); typed filter normalization exists only as unreleased work on `main` |
| Value validation, defaults, fixed values & singular collapse | Yes — `constrain()`/`numeric()`/`integer()`/`uuid()`/`boolean()`/`pattern()` value constraints render a clean 400 before the data layer is touched; `default()`/`fixed()` control absence/presence semantics (a fixed value turns the key into a presence trigger); `singular()` marks a zero-to-one match | Partial — typed filters on `main` (unreleased) hand the filter a normalized value rather than a raw string; no default-value, fixed-value, or singular-collapse semantics are documented anywhere |
| Author-composed filter groups (AND/OR/NOT combinators) | Yes — `WhereAll` (AND) / `WhereAny` (OR) compose child filters server-side into a single named `filter[key]`: fan-out multi-column search, canned toggles via `fixed()` children, arbitrary nesting — author-composed only, by design | Yes, differently — client-facing `filter[and]`/`[or]`/`[not]` boolean algebra shipped in beta.5, gated per resource via `SupportsBooleanFilters`; more client flexibility, larger client-facing query surface (their docs specify no nesting-depth limits) |
| Relationship-path traversal filters (dotted-path EXISTS semi-join) | Yes — `WhereThrough` walks a dotted relationship path as an EXISTS-ANY semi-join (never a fetch-join); `WhereHas`/`WhereDoesntHave` are its length-1 case | Partial — the Laravel binding ships single-level relationship-existence filters (`WhereHas`, `WhereExists`, `WhereCount`, `WhereBelongsTo`); no dotted-path multi-hop traversal or documented EXISTS/semi-join push-down semantics were found |
| Generic per-operator filter syntax & type coercion | No — a real gap: there is no client-usable `filter[field][operator]` grammar. Operator-like filters (`GreaterThan`, `Range`, `DateRange`, …) are separate types the author must declare and bind to a name | Partial — per-operator filtering (e.g. `filter[score][gt]=100`) with type coercion exists on `main`, documented but not in any tagged release |

## Sorting

| Capability | This package | json-api-server |
| --- | --- | --- |
| Declared/computed multi-column sorting with defaults | Yes — `->sortable()` auto-derives a `SortByField`; `sorts()` allows explicit/computed multi-column sorts; the sort handler receives the whole ordered directive list in one call (a cascading comparator contract); `defaultSort()` applies only when `?sort` is absent | Yes — standard comma-separated `sort` with `-` for descending, declarative `sorts()` with visibility and `defaultSort()`. Parity |
| Relationship-count-based sorting primitive | No — a real gap: no named built-in "order by count of a related collection" sort ships; it requires authoring a custom Sort against the Eloquent sort seam rather than dropping in a shipped class | Yes — the Laravel binding ships `SortColumn` and `SortWithCount` as dedicated Eloquent sort classes |

## Pagination

Both offer offset-style and cursor pagination, and both cap page sizes with opt-in
counting — parity on the basics. The differences are strategy *choice* (who picks the
strategy per request) and cursor *reach* (whether cursors work on included collections
and relationship endpoints, not just primary collections).

| Capability | This package | json-api-server |
| --- | --- | --- |
| Strategies offered | Yes — four first-class Paginators (`PagePaginator`, `OffsetPaginator`, `FixedPagePaginator`, `CursorPaginator`) behind one `PaginatorInterface`; see [pagination](pagination.md) | Partial — offset pagination (`page[limit]`/`page[offset]`) is long-standing; cursor pagination shipped in beta.6; the strategy is fixed server-side via the resource's `pagination()` method |
| Client-selectable strategy menu (`page[kind]`) | Yes — the Multi-paginator composes several server-declared strategies; the client selects per request with `page[kind]=<kind>` (unknown kind → 400 `PAGINATION_KIND_UNKNOWN`) or a strategy-unique key, falling back to the author's declared default | No — no client-selectable mechanism exists |
| Cursor pagination on included/related collections | Yes — an included relation's cursor page always renders as a first page (offset-0 keyset with an id tiebreak, `hasMore` via an N+1 probe), with correct profile advertisement even when nested inside a page-based primary document | No — the relationships docs warn verbatim: *"Be careful when making to-many relationships includable as pagination is not supported."* Direct relationship endpoints gained pagination when the related resource is `Listable` (beta.6), but that is a separate endpoint, not the `?include=` compound path |
| Page-size capping, counting controls & totals | Yes — client-controlled page sizes are capped at 100 by default (`withMaxPerPage(0)` disables; an oversized `page[size]` is clamped, not errored); counting is opt-in via `withCount()` or `countable()` + `?withCount=_self_` under the Countable profile; an unpaginated collection renders `meta.total` for free | Yes — default page size 20 with a maximum of 50, customisable (e.g. `new OffsetPagination(defaultLimit: 10, maxLimit: 100)`); totals are opt-in via the `Countable` interface. Parity |
| Cursor pagination on the relationship (linkage) endpoint | Yes — cursor pages render on the queried relationship endpoint including over a pivot join, with a dedicated linkage-page profile-advertisement rule | Partial — beta.6 made to-many responses paginated/filterable/sortable when the related resource is `Listable`, but the relationships docs do not describe those querying capabilities, and no source confirms cursor-specific support or keyset behaviour over a pivot join |

## Sparse fieldsets & includes

| Capability | This package | json-api-server |
| --- | --- | --- |
| Sparse fieldsets (`fields[type]` output narrowing) | Yes — `fields[TYPE]` narrows attributes/relationships (`id` exempt, `notSparseField()` opts out); `sparseByDefault()` makes a field opt-in-only, skipping expensive computation when it is not requested | Yes — standard sparse fieldsets plus a `sparse()` field modifier (excluded unless explicitly requested). Parity |
| Compound documents / includes — safeguards | Yes — `?include` builds compound documents with dedup and primary-takes-precedence; default-included relationships; three composable termination safeguards: `cannotBeIncluded()`, a maximum include depth, and a root-scoped allowed-paths whitelist | Partial — standard includes with include-path validation (to-one relationships link by default, to-many do not); no include-depth limits or whitelisting are documented, and the docs warn against making to-many relationships includable at all because inline inclusion lacks pagination |

## Error handling

json-api-server's model is pragmatic: an `ErrorProvider` interface plus base exception
classes (`BadRequestException`, `UnprocessableEntityException`, `ForbiddenException`,
`ResourceNotFoundException`, and friends) with a `code`/`title` auto-derived in
snake_case from the class name (e.g. `product_out_of_stock`) — the codes exist and the
mechanism is documented. What it does not have is a published catalogue: there is no
table mapping conditions to codes, and no localization layer.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Error catalogue & typed exception model | Yes — 53 concrete typed exception classes, each fixing its own HTTP status, code, title, detail, and source, catalogued in a public ~50-row table; uncaught throwables render a generic debug-gated 500 with PSR-3 logging; see [errors](errors.md) | Partial — `ErrorProvider` plus base exception classes with auto-derived snake_case code/title; the docs explicitly provide no comprehensive catalogue |
| Stable machine-readable error codes | Yes — every error carries a stable SCREAMING_SNAKE code independent of the human copy (`RESOURCE_NOT_FOUND`, `FULL_REPLACEMENT_PROHIBITED`, `PAGINATION_KIND_UNKNOWN`); code and status are never overridden by the localization resolver, and the full mapping is published | Partial — codes exist and are documented at mechanism level; the gap is discoverability and guarantee: no published per-condition catalogue, and no documented resolver-proof stability contract |
| Error message localization | Yes — an `ErrorMessageResolverInterface` resolves `title`/`detail` templates per error code, with `{placeholder}` context interpolation and graceful per-slot fallback to the built-in English catalogue; this package wires it always-on through the Laravel translator (`jsonapi-errors` group) | No — *"All built-in exceptions include sensible default English error messages"*, overridable per-app via `errors()`; no localization layer or per-request locale negotiation |

## Validation / JSON Schema

json-api-server validates at the field level: a typed attribute system (`Str`, `Number`,
`Integer`, `Boolean`, `Date`/`DateTime`, `Arr`/`Obj`/`Any`/`AnyOf`/`AllOf`/`OneOf`/`Not`)
that feeds OpenAPI schema generation, plus closure-based `validate()` and a Laravel
`rules()` bridge. What was not found is any *document-level* schema gate or standalone
JSON Schema export.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Request/response document schema validation | Yes — an optional opis-backed `DocumentValidator` validates decoded documents against vendored JSON:API 1.1 draft-2020-12 schemas, with separate request/response roots and `allOf`+`unevaluatedProperties` relocation so profile fragments can extend permitted members; wired via two opt-in PSR-15 middleware | Not found — validation described in the docs is per-attribute/type-level feeding OpenAPI, not a whole-document schema gate against the JSON:API meta-schema |
| Per-resource JSON Schema publication (create/update fragments, export) | Yes — the schema compiler turns a Resource's field+constraint metadata into per-type create/update draft-2020-12 fragments (`maxLength`, `pattern`, `enum`, formats, nested `Map`/`Obj`/`OneOf`/`Shape`); this package additionally exports standalone per-type JSON Schema documents via artisan, using the same projection as its OpenAPI components, byte-compat-checked against the Symfony bundle | Partial — the typed attribute system drives OpenAPI schema generation, but no standalone JSON Schema export or endpoint separate from the OpenAPI document is documented |
| Runtime value validation & framework rule bridges | Yes — an always-on (not opt-in) bridge translates declared field constraints into real `illuminate/validation` rules with localizable messages, document-first and create/update-context aware, → 422 with `source.pointer`; `UniqueEntity` becomes `Rule::unique` pre-hydration; composite types (`Obj`/`OneOf`/`Shape`) cascade; a `LaravelRules` carrier wraps raw native rules with an optional `->schema()` OpenAPI projection; see [validation](validation.md) | Yes — closure-based `validate()` plus the Laravel `rules()` bridge. Broad parity; how deep the parity runs (always-on vs opt-in, composite cascade, pre-hydration uniqueness) is unconfirmed without reading their implementation |

## Framework fit

Both projects have a genuinely framework-agnostic PSR core — parity worth crediting.
json-api-server's runtime dependencies are the PSR HTTP interfaces plus `nyholm/psr7`,
`doctrine/inflector`, and an accept-header parser; it works with any framework that can
produce and consume PSR-7 messages. The divergence is what "idiomatic in your framework"
means beyond the core: json-api-server has one first-party binding (Laravel), and even
there routing and PSR-7 bridging are wired by hand; this stack ships a Laravel package
and a Symfony twin that each make the same core feel native in their own house style.

| Capability | This package | json-api-server |
| --- | --- | --- |
| Framework-agnostic core (PSR-7/15/17) | Yes — the core package's require block is exactly PHP ^8.3 plus `psr/container`, `psr/http-factory`, `psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware`, and `psr/log`; a bare `Server` + PSR-15 handler is fully functional standalone | Yes — PSR-7/PSR-15-based core with light dependencies; auth and persistence left to the consumer. Parity |
| Native Eloquent/Laravel integration depth | Yes — zero-config discovery (scan `app/JsonApi/`, container-constructed), auto-registered `route:cache`-safe routing with stable `jsonapi.{type}.{action}` names, native Gate policy authorization, 18 real Laravel events plus a per-resource hook trait, `jsonapi:*` artisan commands, an `optimize()`-pipeline production warm-up, and a Laravel-grammar testing kit — all over a storage-agnostic core SPI for custom backends | Yes — a genuinely deep first-party binding (`EloquentResource`, `scope()`, a soft-deletes trait, `can()`/`authenticated()` helpers, `rules()`, Eloquent Filter/Sort classes), but no auto-registered service provider, facade, or artisan commands: routing and PSR-7 bridging are wired manually via the Symfony HTTP Message Bridge and a catch-all route |
| Native Symfony/Doctrine integration depth | Partial — delivered by the sibling [`haddowg/json-api-symfony`](https://github.com/haddowg/json-api-symfony) bundle, which implements the same core contracts this package does (the bundle's code lives in its own repo); the byte-compat pipeline here diffs against its exports on every change | No — no Symfony bundle or Doctrine adapter exists; Symfony appears in their docs only as the HTTP Message Bridge used to convert PSR-7 messages. Laravel is the only framework with bundled first-party support |

## Performance

json-api-server's answer to N+1 is **Deferred Values** — a manual buffering primitive any
adapter can use — plus buffered eager-loading inside the Laravel binding and a phpbench
suite guarding regressions; per-field callback-result caching was considered and
explicitly rejected by the maintainer as infeasible (issue #22). This stack pushes the
problem into typed seams the reference layer implements.

| Capability | This package | json-api-server |
| --- | --- | --- |
| N+1 avoidance for linkage, counts & compound includes | Yes — lazy-by-default linkage for `HasOne`/`HasMany`/`BelongsToMany`/`MorphToMany` (gated by an injected load-state interface) avoids forcing a load just to serialize ids; relationship counts are batched across a fetched page of parents; this package implements the batcher SPI (`RelatedIncludeBatcher`/`RelationCountBatcher`/`RelationshipWindowBatcher`) on Eloquent eager-load internals | Partial — Deferred Values is a manual/DIY buffering primitive for arbitrary adapters; the Laravel binding buffers eager loading internally; a phpbench suite guards regressions |
| SQL push-down for windowed/paginated to-many relations | Yes — a shared, storage-agnostic core seam (`WindowExecutor` plus a keyset toolkit) for LIMIT/OFFSET and keyset push-down; this package's windowed relationship queries (Relationship Queries profile, `?withCount`, paginated related collections) are SQL push-down only, via `groupLimit()`/`ROW_NUMBER() OVER (PARTITION BY …)` with an id tiebreak — no PHP-window fallback, refereed against the in-memory witness | No — no SQL window-function push-down for per-parent-row limiting is documented, and included to-many relations explicitly lack pagination support at all |

## Testing utilities

| Capability | This package | json-api-server |
| --- | --- | --- |
| JSON:API-format-specific test assertions & helpers | Yes — `JsonApiDocument`/`JsonApiErrors` fluent assertions accepting four input shapes (PSR-7 response, raw JSON string, parsed array, unrendered response VO), request/operation builders, and one-line JSON:API 1.1 schema-compliance assertions — all shipped in the runtime autoload, not dev-only | No — no consumer-facing testing helpers ship: composer autoloads only `src/`, with tests isolated under `autoload-dev` |
| Laravel-grammar testing DSL & dual-provider conformance suite | Yes — the `InteractsWithJsonApi` trait gives a typed request-builder DSL (`withInclude`/`withFields`/`withFilter`/`withSort`/`withPage`/`withResource`/`withDocument`/…); `TestResponse` macros (`assertJsonApiDocument`, `assertFetchedOne`, `assertJsonApiSpecCompliant`, …); an opis-gated trait validates real responses against the generated OpenAPI component schemas; and a 48-file dual-provider (Eloquent + in-memory) conformance suite runs on every change; see [multi-server & testing](multi-server-and-testing.md) | No — only the project's own dev-only test suite exists |

## Capabilities beyond the CRUD surface

| Capability | This package | json-api-server |
| --- | --- | --- |
| Soft deletes (recoverable delete, restore/force-delete actions) | Yes — opt-in `#[AsJsonApiResource(softDeletes: true)]` synthesizes discoverable restore (200) and force-delete (204) custom actions (toggleable/renamable) dispatching to Laravel's native `restore()`/`forceDelete()` policy methods for distinct authorization; `DELETE` stays a recoverable soft delete; `WithTrashed`/`OnlyTrashed` filters surface trashed rows; see [soft deletes](soft-deletes.md) | Yes — soft-delete support via the Eloquent `SoftDeletes` trait is part of the `EloquentResource` feature set; the docs do not detail synthesized restore/force-delete endpoints with distinct authorization, so relative depth is unconfirmed |
| Asynchronous processing (202 Accepted / 303 See Other) | Yes — a `DataPersister` returns `AcceptedForProcessing` from `create()`/`update()` to render a spec-correct 202 with `Content-Location`/`Retry-After` pointing at a pollable job resource; the job's fetch (or a custom action) answers 303 on completion; the whole lifecycle is declared in the OpenAPI contract, and async writes are rejected inside an atomic batch (422 `ASYNC_WRITE_IN_ATOMIC_OPERATION`); see [async](async.md) | Yes — the JSON:API Asynchronous Processing recommendation end-to-end (`Create::async` → 202 + `Location`, custom `Retry-After`, `Show::seeOther` → 303), added in the rc.1 cycle. Near-parity on the runtime lifecycle; the differences found are the atomic-interaction guard and OpenAPI modelling of the lifecycle |
| Relationship linkage & mutation controls | Yes — lazy-by-default linkage gated per relationship type and configurable via injected interfaces; native `MorphToMany`; pivot fields surfaced through OpenAPI metadata and pivot-aware cursor conformance tests; relationship mutation endpoints documented separately; see [relationships](relationships.md) | Yes — configurable linkage (`withLinkage`/`withoutLinkage`), `linkageMeta()` for pivot-style meta directly on the linkage object, polymorphic relationships via heterogeneous collections, and an `Attachable` contract validating attach/detach mutations (beta.6). Parity overall — and their named `linkageMeta()` primitive is one this stack has no generalized equivalent of |
| Heterogeneous collections / polymorphism as a first-class construct | Partial — polymorphic Relations are supported with native `MorphToMany` handling; polymorphism is expressed at the relationship level via morph mapping, not as a dedicated top-level multi-type construct | Yes — a first-class `Collection` contract (name, resources, resource-mapping, endpoints) lets one path serve multiple resource types and underlies polymorphic to-one/to-many; consistently well documented. A genuine architectural difference: theirs is the more prominent construct at the top level |
| Custom actions & capability composition without a full resource | Yes — non-CRUD `-actions/{name}` endpoints (resource- or collection-scoped, `None`/`Document`/`Raw` input modes, ability-gated, state-aware `ConditionallyLinked` links); independent capabilities (serializer/hydrator/provider/persister) compose without an `AbstractResource`, with a write-capability guard failing discovery if a write operation is exposed with no hydrator; see [actions](actions.md) and [capability composition](capability-composition.md) | Unknown — no custom-action or capability-composition documentation exists (the docs cover CRUD endpoint pages plus collections/extensions/profiles); the rc.1 endpoint architecture may allow hand-written custom endpoints in code, but nothing equivalent is documented |
| Multi-server support (independent named API surfaces) | Yes — independent named API surfaces (own URI prefix, middleware, optional domain) with per-resource server membership, server-scoped route names, and separate or combined OpenAPI documents per server; see [multi-server & testing](multi-server-and-testing.md) | Unknown — no multi-server concept is mentioned; documented usage is one `$api` instance per application, though nothing prevents constructing several by hand. The difference is first-class support (config, route names, per-server OpenAPI), not raw possibility |
| Response headers (caching, RFC 8594 deprecation) | Yes — declarative per-resource `Cache-Control`/`Vary` headers (with per-read-shape overrides) on successful GETs, plus RFC 8594 `Deprecation`/`Sunset`/sunset-link headers on every response, layered over global config defaults | Unknown — no built-in cache-control or deprecation-header support was found; the docs contain no response-headers page |
| Security posture — documented guarantees & deployer responsibilities | Yes — bounded `JSON_THROW_ON_ERROR` body parsing, linear character-scan header parsing (no ReDoS surface), debug-gated error responses with argument-stripped traces, and allow-list (declared-field-only) write hydration as a structural mass-assignment guard; a dedicated security document states what remains the deployer's responsibility (authn/authz, body-size caps) | Unknown — no dedicated security-guarantees document or hardening checklist exists in the docs; equivalent hardening could exist undocumented, so the defensible claim here is about the *documented* posture |

## Maturity & versioning

Stated descriptively rather than scored. json-api-server's latest tag is
**v1.0.0-rc.1** (2026-01-01); no stable 1.0.0 has ever been cut, after a long alpha/beta
run in which most releases carried breaking changes, and `main` carries further
unreleased work (typed filters, filter operators). The tracker is quiet — two open issues,
Discussions disabled. For this stack, check [Packagist](https://packagist.org/packages/haddowg/json-api-laravel)
and each repo's releases page for the current tags rather than trusting a comparison
document to stay fresh; the capability claims above are about shipped, test-proven
functionality, not a versioning scorecard.

## Summing up

Choose **json-api-server** if you want the leanest possible spec-first core, you are
happy wiring routing and transactions yourself, and you value its genuinely elegant
constructs — heterogeneous `Collection`s, `linkageMeta()`, client-composable boolean
filters, `SortWithCount` — accepting that you are building on a release-candidate
prerelease whose most interesting filter work is still unreleased on `main`.

Choose **this stack** if you want the same spec seriousness with the batteries included:
transactional atomic operations, a client-selectable pagination menu, cursor pagination
that reaches included collections and relationship endpoints, a published error
catalogue with localization, document-level schema validation, OpenAPI with export
tooling and a CI-enforced cross-framework contract, shipped testing utilities — and a
library that is idiomatic in the stack you already chose, whether that is Laravel,
Symfony via the twin bundle, or a custom backend on the framework-agnostic core.
