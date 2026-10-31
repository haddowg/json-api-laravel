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

**Reference pair**:
The `EloquentDataProvider` and `EloquentDataPersister` that serve any type with a resolved model,
at a priority every application provider shadows.
_Avoid_: default provider, Eloquent driver

**Model map**:
The `type → Eloquent model` map the reference pair serves, resolved from explicit wiring, a
declared `model:`, or the convention guess under `model_namespace`.

**Scoping provider**:
An application data provider registered for a type to narrow which of its rows exist for the
caller, such as by tenancy or visibility.

**In-memory witness**:
The `InMemoryDataProvider`/`InMemoryDataPersister` pair the conformance suite runs beside the
reference pair, so a divergence is attributable to the data layer rather than the wiring.
_Avoid_: fake provider, mock store

**Filter arm**:
A registered handler that teaches the Eloquent provider to apply one filter class; a **sort arm**
does the same for a sort.
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

**Operation allow-list**:
The set of CRUD operations a type exposes, which decides the routes registered for it.

**Mount type**:
The type an action's URL hangs off, and its default request and response type.

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
for an identical domain, checked by `composer byte-compat`.
_Avoid_: parity check

**Workbench**:
The Testbench application under `workbench/` that serves the example domains the docs and tests
run against.
