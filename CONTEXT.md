# json-api-laravel

The Laravel integration of `haddowg/json-api`: it serves the standard JSON:API endpoint set for
discovered resources over an Eloquent reference data layer. Core concepts (resource, serializer,
hydrator, relation, profile, server and the rest) are defined in
[haddowg/json-api's CONTEXT.md](https://github.com/haddowg/json-api/blob/main/CONTEXT.md); this
glossary covers only the terms this package adds.

## Language

### Data layer

**Data provider**:
The read half of the data-access SPI: the component that fetches one resource, a collection or a
relation's members for the types it `supports()`.
_Avoid_: repository, data source

**Data persister**:
The write half of the data-access SPI: the component that instantiates, creates, updates, deletes
and mutates relationships for the types it `supports()`.
_Avoid_: writer, saver

**Write transaction**:
The transaction a single create, update, delete, relationship write, or action called with a writing method runs in, on a persister that implements `TransactionalDataPersisterInterface`: from its first write step and every Before* event through the persister's write. A CRUD write's After* events fire once it commits; an action's `AfterActionEvent` fires inside it.
_Avoid_: savepoint (a listener's own transaction nests as one inside it)

**After-commit callback**:
A callable added to the `AfterCommit` service, or through `ActionContext::afterCommit()`, during a write. It runs once the outermost write transaction commits, before the After* events, and is dropped on a rollback. A write with no transaction runs its callbacks once it returns, an Atomic Operations batch once the batch commits, and outside any write a callback runs at once. While the application holds a transaction open on any resolved connection, they wait for its commit.
_Avoid_: post-commit hook (the deferred After* dispatch an Atomic Operations batch queues)

**Reference pair**:
The `EloquentDataProvider` and `EloquentDataPersister` that serve any type with a resolved model,
at a priority every application provider shadows.
_Avoid_: default provider, Eloquent driver

**Model map**:
The `type → Eloquent model` map the reference pair serves, resolved from explicit wiring, a
declared `model:`, or the convention guess under `model_namespace`.

**Foreign-key read**:
How a relation declared with a foreign key (core's term) is read: always by the related type's provider, through `ReadsByForeignKeyInterface`, as the rows whose foreign key holds one of a page of parents' keys, whatever provider serves the parents. The parent model never holds the relation, and a write of it sets or clears the foreign key on the related rows through the related type's persister (`WritesForeignKeysInterface`).
_Avoid_: inverse read, reverse lookup

**Scoping provider**:
An application data provider registered for a type to narrow which of its rows exist for the
caller, such as by tenancy or visibility.

**In-memory witness**:
The `InMemoryDataProvider`/`InMemoryDataPersister` pair the conformance suite runs beside the
reference pair, so a divergence is attributable to the data layer rather than the wiring.
_Avoid_: fake provider, mock store

**Filter arm**:
A registered handler that teaches the Eloquent provider to apply one filter class; a **sort arm**
does the same for a sort. A **keyset sort arm** also supplies the expression its sort orders a
cursor page by.
_Avoid_: filter driver, filter strategy

**Include batcher**:
The provider-agnostic component that loads the related data a page of parents needs in one
batched call per relation level.

**Accepted for processing**:
The marker a data persister returns instead of an entity to say a write was dispatched for
asynchronous processing.

### Discovery and registration

**Discovery**:
The scan of the configured discovery paths that classifies resources, standalone serializers and
hydrators, actions and described errors from their attributes.

**Discovery snapshot**:
The file `jsonapi:optimize` writes so a production app boots from discovery's results, model map
and scope tables without scanning.
_Avoid_: discovery cache, manifest

**Standalone serializer**:
A serializer registered for a type with `#[AsJsonApiSerializer]` and no resource; a **standalone
hydrator** is its write-side counterpart.
_Avoid_: resource-less resource

**Related-only type**:
A type declared `#[AsJsonApiResource(relatedOnly: true)]`: it serves no endpoint of its own and is
reached only as another type's related or included resources, or as a custom action's `inputType`.
_Avoid_: embedded type, endpointless type

**Singleton type**:
A type with one resource per caller, served at `/{uriType}` with no `{id}`. It resolves the
caller's id once per request and then runs the ordinary fetch, update, relationship and action
paths with it; it keeps its real id everywhere else.
_Avoid_: current-user resource, me endpoint

**Operation allow-list**:
The set of CRUD operations a type exposes, which decides the routes registered for it.

**Mount type**:
The type an action's URL hangs off, and its default request and response type.

**Filtered collection**:
The mount type's rows a collection action's validated `filter[...]` parameters select, read
through `ActionContext::filtered()` with no sort or page window.

**Typed document meta**:
The top-level `meta` members a type's `documentMeta()` declares for one operation's document,
or an action handler's `documentMeta()` for one response status, which the CRUD handler fills
from their `extractUsing()` hooks and, under `app.debug`, the controller and the atomic batch
check.
_Avoid_: response meta

**Typed request meta**:
The top-level `meta` members a type's `requestDocumentMeta()` declares for one write, or an
action handler's for one HTTP method, which the CRUD handler and the action invoker validate as
attributes before they hydrate and put on the request as the written meta.
_Avoid_: request options

**Declared resource meta**:
The `meta` members a type's `resourceMeta()` declares for its resource objects, rendered from
their `extractUsing()` hooks on a read and validated, then filled through `fillUsing()` or left
on the written meta, on a write.
_Avoid_: resource meta (alone, it also names what `getMeta()` returns)

**Written meta**:
The typed values of a write's top-level `meta` and `data.meta`, carried on its request, which
the hydrator, the lifecycle events, the hook context and an action's context read.

**Servability**:
Whether every discovered type can actually be served against its data layer: its columns,
relation methods, morph maps, provider and persister all resolve. `jsonapi:optimize` checks it.

### Routing and requests

**JSON:API route**:
A route carrying the `_jsonapi` marker default, whether the package registered it or the app
placed it with `Route::jsonApi()`; `JsonApiRoute` describes the one a request matched.
_Avoid_: API route

**Combined mode**:
Serving one OpenAPI document that covers every server, instead of one document per server.

### Authorization

**Guarded type**:
A type whose rows a caller may not all see: one with an Eloquent global scope, a scoping provider,
or a `view` check that can refuse a row.

**Read permission**:
A relation's own declared answer to whether the caller may see its data, replacing the related
type's gates for that relation.

**Scope table**:
A server's resolved map from operations to the OAuth scopes they require.

### OpenAPI serving

**Serve decorator**:
A tagged service that edits the warmed OpenAPI document or JSON Schema per request before the docs
controller serves it.
_Avoid_: runtime decorator

**Warmed artifact**:
A document `optimize` pre-builds and stores so the docs controllers serve it without a build.

### Parity

**Byte-compat**:
The guarantee that this package and the Symfony bundle project byte-identical OpenAPI documents
for an identical domain, checked by `composer byte-compat` against the bundle's published
documents.
_Avoid_: parity check

**Workbench**:
The Testbench application under `workbench/` that serves the example domains the docs and tests
run against.
