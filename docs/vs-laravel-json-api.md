# vs Laravel JSON:API

[Laravel JSON:API](https://laraveljsonapi.io) (`laravel-json-api/laravel`) is the established
JSON:API implementation for Laravel: a lineage reaching back to 2015, years of production
hardening, an excellent versioned docs site, and a steady maintenance cadence. If you want the
most widely deployed, community-proven choice today, it is a very good one. This package makes
a different bet: a single **typed resource definition** drives serialization, hydration,
**validation**, and a first-party **OpenAPI 3.1** document — over the full JSON:API **1.1**
surface, with atomic operations, profile negotiation, composite attribute types, and true
keyset cursor pagination. This page draws that contrast feature by feature, as fairly as we
can.

Comparison as of 5 July 2026, against `laravel-json-api/laravel` v5.2.1 and the Laravel
JSON:API 5.x documentation. Found an error?
[Open an issue](https://github.com/haddowg/json-api-laravel/issues).

## Spec compliance

Both packages take the specification seriously. Laravel JSON:API strictly parses inbound
documents (a malformed document is a `400` with a JSON Pointer before your validation ever
runs) and enforces the media type on every request — but it targets **JSON:API 1.0**, and the
1.1 additions are where the two diverge: `ext`/`profile` media-type parameters, `lid`, and the
[Atomic Operations extension](atomic-operations.md).

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| JSON:API version | **Yes** — full 1.1 document structure and endpoint semantics, backed by a spec-compliance suite in the framework-agnostic core | **Partial** — spec-compliant on 1.0 (responses advertise `"version": "1.0"`); strict inbound document parsing; no 1.1 additions |
| Content negotiation | **Yes** — strict `Accept`/`Content-Type` handling including `ext` and `profile` parameters (`415`/`406` on violations), a profile registration system, and the published cursor-pagination profile bundled | **Partial** — strict `406`/`415` on the bare `application/vnd.api+json` media type; no `ext`/`profile` parameter handling |
| Atomic operations | **Yes** — opt-in per server: all-or-nothing batches, `lid` references, spec-shaped result documents, [lifecycle hooks](lifecycle.md) per operation | **No** — not implemented (long-standing open feature requests) |
| Error objects | **Yes** — every failure is a spec-shaped error document with pointer or parameter sources, including nested pointers into [composite attributes](composite-attributes.md); see [errors](errors.md) | **Yes** — `400`s with pointers from spec parsing, `422`s with pointers from rule keys, and an exceptions package converting custom exceptions |

## Resource definition

Both are schema-first: you declare typed fields with storage mapping, visibility scoping, and
serialize/deserialize hooks. The decisive difference is what the definition is *for*. In
Laravel JSON:API, schema fields describe serialization only — validation deliberately lives in
separate hand-written request classes. Here, the same field declaration carries its
constraints, and those constraints are what [validate writes](validation.md) and what the
[OpenAPI document](openapi.md) is generated from.

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| Typed field system | **Yes** — `Str`, `Integer`, `Decimal`, `Boolean`, `DateTime`/`Date`/`Time`, `Email`/`Url`/`Uuid`/`Slug`/`Ip`, `ArrayList`, `ArrayHash`, with per-type constraint helpers on one builder surface; see [resources](resources.md) | **Partial** — declarative fields with column mapping, visibility, and hooks, but a narrower palette (no semantic `Email`/`Url`/`Uuid` types) and fields carry no validation constraints |
| Composite attributes | **Yes** — `Map` (nested wire object over flat columns), `Obj` (typed object over one `json` column), `OneOf` (discriminated union), and the `Shape` constraint, all validated with nested source pointers; see [composite attributes](composite-attributes.md) | **Partial** — a typed `Map` field over flat columns; `ArrayHash`/`ArrayList` cover `json` columns but are untyped; no discriminated unions; nested pointers only via hand-written dot-notation rules |
| Encoded ids | **Yes** — an attachable encoder decouples the wire id from the storage key, honoured through serialization, filters, and the data layer | **Yes** — a core `IdEncoder` contract honoured through routing, relationships, and id filters, plus a first-party HashIds package |
| Many types, one model | **Yes** — multiple resource types project the same model with independent fields, operations, and `uriType`; see [capability composition](capability-composition.md#one-model-two-types) | **Yes** — via proxy classes: each extra type needs a small proxy wrapper, and schemas can set `uriType` |
| Capability composition | **Yes** — type-level: per-operation allow-lists, `readOnly` shorthand, per-relation endpoint gating, down to a bare serializer/hydrator pair with no resource class | **Yes** — route-level: `only()`/`except()`/`readOnly()` on resource and relationship routes; resource classes are optional |

## Reading

Query-surface parity is high — sparse fieldsets, nested includes, multi-field sorting, and a
deep declarative filter vocabulary exist on both sides. Three things separate them: querying
*included* collections, cursor pagination semantics, and how compound documents are bounded in
SQL.

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| Fieldsets & includes | **Yes** — `fields[TYPE]`, nested include paths with depth caps and per-relation opt-outs, plus per-relationship sort/filter on included collections via the [Relationship Queries profile](relationships.md#the-relationship-queries-profile) | **Partial** — full fieldsets and includes with a configurable depth cap and per-relation opt-outs; no per-relationship querying of included collections |
| Filtering & sorting | **Yes** — `Where`, `WhereIn`/`NotIn`, `WhereNull`, `WhereHas`/`WhereDoesntHave`, dotted-path `WhereThrough`, singular filters, pre-validated values; see [eloquent](eloquent.md#filters--query-builder) | **Yes** — a rich Eloquent-native vocabulary including scopes, pivot filters, and `WhereAll`/`WhereAny`; custom filter classes cover dotted paths |
| Pagination strategies | **Yes** — page-number, offset, server-fixed, and cursor, with defaults, caps, and a per-relation resolution chain; see [pagination](pagination.md) | **Yes** — page-based (length-aware or count-free) and cursor, plus `MultiPagination` letting the client choose per request |
| Cursor pagination | **Yes** — true keyset windows under any declared `?sort` (a deterministic id tie-breaker is always appended), on primary, related, and linkage endpoints, advertising the published cursor-pagination profile | **Partial** — true keyset (id-ordered; the docs advise disabling sort with it) wherever the schema's paginator runs, including relationship endpoints; the cursor-pagination profile is not advertised |
| Compound-document bounding | **Yes** — windowed includes and related collections run as SQL window functions and push-down queries, never full-collection hydration; see [eloquent](eloquent.md#windowed-relationship-queries--sql-push-down-only) | **No** — includes use standard Eloquent eager loading, which hydrates full related collections per path |

## Writing & validation

CRUD and relationship mutation are solid on both sides: auto-registered write endpoints with
correct status codes, client-generated ids, relationship attach/detach with per-relation
authorization. The divergence is validation. Laravel JSON:API's docs defend keeping rules in
hand-written, form-request-style classes as a deliberate design choice — and if your team
wants the full expressiveness of hand-rolled Laravel rules per endpoint, that is a genuine
point in its favour. This package takes the opposite bet: the field constraints *are* the
validation, compiled to `illuminate/validation` rules with nested source pointers, an
entity-level post-hydration pass, and an optional document-first structural linter — and the
same constraints project into the OpenAPI document. (Note the flip side: there is deliberately
[no FormRequest integration](validation.md#non-goal-formrequest-integration) here.)

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| Full CRUD | **Yes** — all five operations auto-exposed per type (`201` + `Location`, `200`, `204`), client-generated-id support; see [routing](routing.md) | **Yes** — auto-registered via the route registrar with generic controller actions and `clientIds()` support |
| Relationship mutation | **Yes** — Replace/Add/Remove vocabulary with per-relation `cannotReplace()`/`cannotAdd()`/`cannotRemove()` flags rejecting as `403` with typed error codes; see [relationships](relationships.md#relationship-mutations-and-prohibitions) | **Yes** — `PATCH`/`POST`/`DELETE` on linkage endpoints, gated per relation by routing and per-relation policy methods |
| Validation from the definition | **Yes** — constraints compile to Laravel rules, `422`s with pointers into nested composites, entity-level pass, optional JSON Schema linter; see [validation](validation.md) | **Partial** — rules are hand-written per resource request class (a documented, deliberate choice), yielding `422`s with dot-notation pointers; relationship identifier existence is auto-checked |
| Lifecycle & custom actions | **Yes** — 18 Laravel [events](lifecycle.md) plus a per-resource [hook trait](lifecycle-hooks.md); non-CRUD [actions](actions.md) with typed input modes, per-action authorization, and `asLink` exposure | **Yes** — controller hooks around every action (returning a response short-circuits), Eloquent model events, and custom actions via `->actions()` mapping to controller methods |

## Data layer

Laravel JSON:API ships one comprehensive first-party layer — `laravel-json-api/eloquent`,
covering reads, writes, eager loading, pagination, soft deletes, and encoded ids — with a
separate toolkit for non-Eloquent sources. This package's [Eloquent layer](eloquent.md) is
also a *reference* implementation behind a service-provider interface: reads and writes flow
through `DataProvider`/`DataPersister` contracts resolved by priority, so a custom provider
cleanly shadows the built-in layer per type (see
[custom data providers](custom-data-providers.md)), and a shipped in-memory pair doubles as a
test double and conformance witness.

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| Provider/persister seam | **Yes** — separately registered `DataProvider`/`DataPersister` services, priority + first-supports resolution | **Yes** — `Store` contracts resolved via each schema's `repository()`; the non-eloquent package provides the toolkit |
| Eloquent reference layer | **Yes** — a three-tier type-to-model map (explicit wiring, `model:` declaration, namespace convention), transactional writes, batched includes, encoded ids; see [eloquent](eloquent.md) | **Yes** — comprehensive: reads, writes, eager loading, filters, sorting, first-class soft deletes; the model is declared per schema |
| First-class soft deletes | **Yes** — `softDeletes: true` synthesizes `restore`/`force-delete` actions (self-documented in OpenAPI, gated by the model's native `restore()`/`forceDelete()` policy methods, restore exposed as a trashed-only link); `DELETE` stays a recoverable soft delete; `WithTrashed`/`OnlyTrashed` filters + a `trashed` meta flag; see [soft deletes](soft-deletes.md) | **Yes** — `DELETE` force-deletes and trash/restore is a `PATCH` of a writable tombstone attribute; `WithTrashed`/`OnlyTrashed` filters; no OpenAPI, and trash/restore share the `update` ability |
| In-memory provider | **Yes** — a reusable in-memory pair ships with the package, and the docs' example suites run over both it and the database layer; see [workbench](workbench.md) | **Partial** — the non-eloquent toolkit lets you build one; nothing reusable ships as a test double |

## OpenAPI & tooling

This is the headline difference. Laravel JSON:API has no first-party OpenAPI support; the
community addon (`swisnl/openapi-spec-generator`) generates an OpenAPI **3.0** document from
your servers and schemas, but — precisely because validation rules live in hand-written
request classes rather than on schema fields — it cannot fully derive request-body
constraints. Here the OpenAPI **3.1** document is generated from the exact definitions that
serialize and validate, so it is complete by construction.

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| OpenAPI document | **Yes** — first-party 3.1 covering every type, CRUD, relationship, related, and custom-action route, with Swagger UI / ReDoc viewer routes and a customization seam; see [openapi](openapi.md) | **Partial** — community addon generating OpenAPI 3.0 via an artisan command; request-body constraints cannot be fully derived from hand-written request classes |
| Export & warmup | **Yes** — `jsonapi:openapi:export`, standalone per-type JSON Schema exports, and `jsonapi:optimize` in the deploy pipeline; see [optimize](optimize.md) | **Partial** — the addon writes the document to storage; no JSON Schema export or warmup story |
| Cross-framework contract | **Yes** — the Symfony and Laravel integrations emit a byte-identical document, enforced by a byte-compat CI job; see [openapi](openapi.md#byte-compatibility-with-the-symfony-bundle) | **No** — a Laravel-only package by design, so a cross-framework contract does not apply |
| Testing kit | **Yes** — document/error assertions, request builders, and a `SchemaConformanceTrait` proving the generated document against real responses; see [testing](multi-server-and-testing.md#testing) | **Yes** — a first-party testing package with fluent JSON:API assertions and a runnable tutorial app; nothing proves a generated document against responses |

## Framework, runtime & authorization

Both are deeply idiomatic Laravel — package config, cache-safe routing, artisan tooling, Gate
policies with natural ability names, and relationship-level authorization checked against the
loaded model. The registration philosophy differs (attribute discovery here, explicit
server/schema registration there), and only one of the two documents a long-running-worker
posture.

| Capability | This package | Laravel JSON:API |
| --- | --- | --- |
| Integration style | **Yes** — zero-config `#[AsJsonApiResource]` discovery with auto-registered, `route:cache`-safe routes; see [routing](routing.md) and [configuration](configuration.md) | **Yes** — explicit registration (servers in config, schemas in the `Server` class) with a route registrar and artisan generators for every class type |
| Long-running runtimes | **Yes** — a documented Octane / queue-worker posture with scoped bindings and warmed artifacts; see [optimize](optimize.md) | Not documented |
| Multi-server | **Yes** — servers run side by side with per-server resources, config, routes, and OpenAPI documents; see [multi-server](multi-server-and-testing.md#multi-server) | **Yes** — first-class: any number of servers with their own schemas, base URI, routing, and middleware |
| Authorization | **Yes** — Gate policies per operation with natural ability names, per-relation security, per-object checks, and request-aware field predicates; see [authorization](authorization.md) | **Yes** — automatic Gate/policy authorization per operation, per-relationship policy methods, and customisable `Authorizer` classes |

## Where Laravel JSON:API shines

Credit where it is due — there are real reasons it is the default choice:

- **Maturity and community.** A lineage back to 2015 (as successor to
  `cloudcreativity/laravel-json-api`), years of production use, an established issue-tracker
  and Stack Overflow knowledge base, and a steady release cadence — Laravel 13 support landed
  within weeks of the framework release.
- **Documentation.** A versioned docs site with a full tutorial and a runnable tutorial-app
  repository.
- **Generator ergonomics.** Artisan generators for every class type
  (`jsonapi:server`/`schema`/`resource`/`requests`/`filter`/`sort-field`/`authorizer`).
- **Client-selectable pagination.** `MultiPagination` lets a client choose page-based or
  cursor pagination per request; here a resource declares one strategy.
- **The hand-written-rules idiom.** If your team wants form-request-style validation classes
  with the full, unconstrained Laravel rules vocabulary, that is exactly what it provides.
- **A first-party HashIds package** for id obfuscation out of the box.

## Which should you choose?

**Choose Laravel JSON:API** if you want the safer bet: a battle-tested package with years of
production hardening, a large installed base, community answers to almost any question, and
features this package lacks — client-selectable pagination and form-request-style validation.
This package is newer, without the same installed base or
plugin ecosystem; that maturity gap is real and should weigh heavily for risk-sensitive
projects.

**Choose this package** if the contract is the point: one typed definition driving
serialization, validation, *and* a first-party OpenAPI 3.1 document that a
[conformance trait](multi-server-and-testing.md#schema-conformance) proves against the
responses actually served; full JSON:API 1.1 including
[atomic operations](atomic-operations.md) and profile negotiation; keyset
[cursor pagination](pagination.md#cursor-keyset-pagination) on every collection endpoint;
[composite attributes](composite-attributes.md) making structured `json` columns first-class
citizens; and — if you run both frameworks — a
[byte-identical contract](openapi.md#byte-compatibility-with-the-symfony-bundle) between the
Laravel package and the [Symfony bundle](https://github.com/haddowg/json-api-symfony), so one
generated client consumes either backend unchanged.
