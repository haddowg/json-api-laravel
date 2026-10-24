# OpenAPI & documentation

The package projects an **OpenAPI 3.1** document from your discovered resources — no
hand-written spec. It serves a Swagger UI / ReDoc viewer, exposes the document + JSON Schema
over HTTP, and exports them from the CLI. Crucially, for an identical domain the document is
**byte-identical to the Symfony bundle's** (PLAN decision 11), so a `json-api-ts` client
generator consumes either backend unchanged.

## What is projected

Everything the resources declare becomes the document: paths (one per type × operation),
parameters (filter / sort / page / fields / include), request/response schemas,
`components.schemas` (per-type schemas + reusable enum components), tags, and security. A
backed-enum field (`status->enum(AlbumStatus::class)`) projects a reusable
`#/components/schemas/AlbumStatus` with described cases. [Custom actions](actions.md) and the
[atomic](atomic-operations.md) endpoint project too.

The error codes a server can raise are catalogued as one named schema variant each, so a
generated client gets a typed exception per code rather than one opaque error type. Core's
codes are always there; your own join them when discovery finds them — see
[errors](errors.md#documenting-your-own-error-codes).

## Per-operation response declarations {#per-operation-response-declarations}

By default each operation advertises one success response — `POST` → `201`, `PATCH` → `200`,
`DELETE` → `204`, `GET` → `200`. A resource overrides the set per operation with the
[`create`/`update`/`delete`/`fetchOne`/`fetchCollection` params](resources.md#declaring-response-shapes)
on `#[AsJsonApiResource]`, and a [custom action](actions.md) with its `responds` set. This is
how the **asynchronous-write lifecycle** ([async writes](async.md)) becomes part of the
contract:

- `new Accepted('export-jobs')` → a `202 Accepted` whose body is the `export-jobs` document,
  with `Content-Location` (the poll URL) and `Retry-After` headers.
- `new SeeOther()` on `fetchOne` → a `303 See Other` with a `Location` header (the completed
  job redirecting to the produced resource), no body.
- `new NoContent()` → a `204`; `new MetaResult()` → a `200` meta-only document.

So a codegen client sees, from the document alone, that `POST /catalog-exports` returns `202`
pointing at an `export-jobs` resource, and that `GET /export-jobs/{id}` may `303` to the
finished export. Because the responses ride core's shared projection, they are part of the
[byte-compatible](#byte-compatibility-with-the-symfony-bundle) output.

## Vendor extensions (`x-`) {#vendor-extensions}

A resource can stamp OpenAPI vendor extensions on its operations, for tooling that reads them
(a gateway, a docs portal, a client generator). Declare them on `#[AsJsonApiResource]`:

```php
#[AsJsonApiResource(
    extensions: ['x-feature' => 'agency'],
    operationExtensions: [
        'Create' => ['x-rate-limit' => ['per' => 'minute', 'limit' => 10]],
        'Delete' => ['x-feature' => 'agency-admin'],
    ],
)]
final class AgencyResource extends AbstractResource { /* ... */ }
```

`extensions` lands on every operation the document emits for the type: the CRUD operations,
the related and relationship endpoints of its relations, and its [custom actions](actions.md).
`operationExtensions` adds members to one CRUD operation, keyed by the `Operation` case value
(`FetchCollection`, `FetchOne`, `Create`, `Update`, `Delete`), and is merged over `extensions`,
so the operation's value wins on a shared key. Here `DELETE /agencies/{id}` carries
`x-feature: agency-admin` and every other operation `x-feature: agency`.

Discovery checks the declaration, so a mistake fails at boot rather than in the document:

- every key must start with `x-` and name something after it;
- `x-oai-` and `x-oas-` are reserved by the OpenAPI Initiative and rejected;
- a value is a scalar, `null`, or an array of them, since it is stored in the
  [discovery cache](optimize.md);
- an `operationExtensions` key must be an `Operation` case the type exposes.

A type that declares none projects exactly as before.

## The HTTP routes

When `jsonapi.openapi.enabled` is true **and** (`app.debug` OR `openapi.expose_in_prod`), the
package serves — API-wide, not under a server prefix:

| Route | Serves |
| --- | --- |
| `GET /docs.json` | the default server's OpenAPI document |
| `GET /{server}/docs.json` | a named server's document (e.g. `/admin/docs.json`) |
| `GET /schemas.json` | the standalone JSON Schema documents |
| `GET /docs` | the viewer UI (Swagger UI by default; ReDoc optional) |

Configure the paths, the renderer, and the prod gate under `jsonapi.openapi` — see
[configuration](configuration.md#openapi). While the document is served, every link the
package builds points at its operation in it; see
[links and `describedby`](#links-and-describedby).

## Links and `describedby` {#links-and-describedby}

The links the server builds (each resource's `self`, relationship `self` and `related`,
the top-level `self`, pagination links and `asLink` action links) render as JSON:API link
objects. When the document is served, each one carries a `describedby` pointing at the
operation in the document that describes its target, and the document gets a top-level
`describedby` naming the operation that produced it:

```jsonc
// GET /api/albums/1
{
  "data": {
    "type": "albums",
    "id": "1",
    "links": {
      "self": {
        "href": "https://music.example/api/albums/1",
        "describedby": "/docs.json#/paths/~1albums~1%7Bid%7D/get"
      },
      "reissue": {
        "href": "https://music.example/api/albums/1/-actions/reissue",
        "describedby": "/docs.json#/paths/~1albums~1%7Bid%7D~1-actions~1reissue/post"
      }
    },
    "relationships": {
      "artist": {
        "links": {
          "self": {
            "href": "https://music.example/api/albums/1/relationships/artist",
            "describedby": "/docs.json#/paths/~1albums~1%7Bid%7D~1relationships~1artist/get"
          },
          "related": {
            "href": "https://music.example/api/albums/1/artist",
            "describedby": "/docs.json#/paths/~1albums~1%7Bid%7D~1artist/get"
          }
        }
      }
    }
  },
  "links": {
    "self": {
      "href": "https://music.example/api/albums/1",
      "describedby": "/docs.json#/paths/~1albums~1%7Bid%7D/get"
    },
    "describedby": { "href": "/docs.json#/paths/~1albums~1%7Bid%7D/get" }
  }
}
```

The fragment is a JSON Pointer to the path and method in `paths`. An action link names the
action's first declared method. A link whose operation the document doesn't carry gets no
`describedby`, so none points at a fragment that isn't there.

The document URL is the path of the server's document route, host-relative and including
any prefix or base path it is served under: `/docs.json` for the default server and
`/{server}/docs.json` for a named one, or `/docs.json` for every server in combined mode.
When the document lives somewhere else, such as a docs site or a CDN serving the
[`public_path`](#warming-for-production) files, name it with `document_url`. It's used as
given, with `{server}` replaced by the server name (or `combined` in combined mode):

```php
// config/jsonapi.php
'openapi' => [
    'document_url' => 'https://docs.music.example/openapi/{server}.json',
],
```

Links get no `describedby` when the document isn't served and no `document_url` is set
(the expose gate is closed, or generation is disabled), or when `describedby` is `false`.
They stay link objects either way. To render bare URL strings instead, set
`jsonapi.link_objects` to `false`, or the same key on a
[server](configuration.md#servers). A string can't carry a `describedby`, but the
document's own top-level one still renders.

An [Atomic Operations](atomic-operations.md) result's top-level `describedby` names the
atomic endpoint's `POST` (`/docs.json#/paths/~1operations/post` with the default
`atomic_operations.path`), and the resource objects inside it carry the same links as
anywhere else. Error documents get none.

A `describedby` set on a response with `withDescribedby()`, or one inside its `withLinks()`,
replaces the per-operation one, so set it only when a response should point somewhere else.

The generated document describes these links: each `<Type>ResourceLinks`,
`<Type>DocumentLinks`, `<Type>CollectionLinks` and `<Type><Rel>RelationshipLinks`
component lists the members that context renders, with an example carrying the same
`describedby`, and an `asLink` action is listed by its name.

`artisan optimize` writes a small map of each document's operations beside it, which is all
a request reads to resolve a `describedby`. Without it, a request reads the operations from
the stored document, or builds the document once for the process when there is none; with
`app.debug` on, the map is then written for later requests.

## The CLI exporters

The export commands are **always available** (independent of the HTTP expose gate):

```bash
php artisan jsonapi:openapi:export --server=default --output=build/openapi.json
php artisan jsonapi:openapi:export --server=admin --format=yaml
php artisan jsonapi:jsonschema:export --server=default --output=build/schemas
```

`--server` selects the server (default `default`); `--format` is `json` (default) or `yaml`;
`--output` writes to a file/directory (omit for stdout). The document renders byte-clean
(`toJsonString(true)."\n"`).

## Profile-gated parameters (`x-profile`)

Some query parameters are recognised by the runtime **only when a JSON:API
[profile](https://jsonapi.org/format/#profiles) is negotiated** on the request (its URI
supplied via the `profile` media-type parameter of `Accept` / `Content-Type`). `withCount`
is the built-in case — the count tokens are honoured only under the Countable profile.

The projected document advertises this constraint honestly: every such parameter carries an
**`x-profile`** vendor extension whose value is the profile URI that must be negotiated for
the parameter to take effect. A client (or a codegen tool) that does not negotiate that
profile should treat the parameter as inert. The `withCount` parameter on a countable
collection (`HasMany::make('albums')->countable()`) is emitted as:

```json
{
  "name": "withCount",
  "in": "query",
  "schema": { "type": "array", "items": { "type": "string", "enum": ["tracks"] } },
  "style": "form",
  "explode": false,
  "x-profile": "https://haddowg.github.io/json-api/profiles/countable/"
}
```

The extension is produced by **core's projection**, so it is present on every served build
path — the [warmed artifact](#warming-for-production), the document/viewer controllers, and
the [CLI export](#the-cli-exporters) alike; the package serves the projected document
verbatim. Because it rides the shared projection, it is part of the
[byte-compatible](#byte-compatibility-with-the-symfony-bundle) output — you can grep any
exported document (e.g. `build/laravel-default.json`) for `x-profile` to see it.

## The `info`, `tags`, and `security` blocks {#security}

Fill the document metadata under `jsonapi.openapi`:

```php
'openapi' => [
    'info' => [
        'title' => 'Music Catalog API', 'version' => '1.0.0',
        'description' => 'A JSON:API music catalogue.',
        'contact' => ['name' => 'Catalog Team', 'email' => 'api@music.example'],
        'license' => ['name' => 'MIT'],
    ],
    'tags' => [
        ['name' => 'Catalog', 'description' => 'Albums and album actions.'],
        ['name' => 'Library', 'description' => "A user's saved playlists."],
    ],
    'security' => [
        'schemes' => ['bearer' => ['type' => 'bearer', 'bearerFormat' => 'JWT']],
        'default_requirement' => [['name' => 'bearer']],
    ],
],
```

A resource groups its operations under `tags:` (a type referencing an undefined tag falls back
to a humanized default). Security is **declared**, not derived from runtime enforcement, so the
projection is stable: the `default_requirement` is the document-level requirement, and a
secured operation (via [`abilities`](authorization.md), a relation `security`, or an action
`ability`) inherits it (a `401`). A read declared `abilities: ['read' => false]` projects
`security: []` (public).

## The `servers` list

Unless `jsonapi.openapi.servers` overrides it, each document's `servers` comes from the
JSON:API server it describes:

- **A configured `base_uri`** (global or the server's own) is the one server URL, as it is
  (`https://api.example.com/api`).
- **An empty `base_uri`** advertises the prefix the server's routes are registered under,
  as a relative URL, so a client resolves `/albums` against the document's host as
  `/api/albums`. Routes registered with no prefix advertise no `servers`. A server whose
  routes sit under two prefixes (say, auto-registered under `api` and placed again with
  `Route::jsonApi()` under `v2`) gets one entry per prefix, sorted, and an unprefixed
  placement beside them adds `/`.
- **A prefix with route parameters** (`{tenant}/api`) becomes a templated URL with a server
  variable whose `default` is the route default for that parameter, set with the server's
  `route_defaults` key:

```php
'servers' => [
    'default' => ['prefix' => '{tenant}/api', 'middleware' => [], 'domain' => null, 'route_defaults' => ['tenant' => 'acme']],
],
```

```json
"servers": [{ "url": "/{tenant}/api", "variables": { "tenant": { "default": "acme" } } }]
```

OpenAPI requires a variable default, so a parameterised prefix without a route default is
left out of `servers` rather than given an invented value.

The list is read from the registered routes, not from a request, so it is the same whether
`optimize` or the first request builds the document. That also means it can't see an app
served below the web root (`/app/index.php`) or a proxy prefix: those are only known per
request. Set `jsonapi.openapi.servers` (or a configured `base_uri`) when the API is reached
through one. In `combined` mode the document lists every server's entries, deduplicated by
URL.

## Multi-server

`openapi.multi_server` chooses `separate` (one document per server, per-server routes — the
default) or `combined` (a single union document, requires non-colliding types across servers).
The example uses `separate` and exports both the `default` and `admin` documents.

## Byte-compatibility with the Symfony bundle

Because both integrations implement core's `Metadata/*` contract, the projected document for an
identical domain is byte-identical (bar the `info` block and advertised server URLs, which are
platform-legitimate). The music-catalog workbench exists to prove it: `composer byte-compat`
exports both twins' `default` + `admin` documents, normalizes `info`/`servers[].url`, and diffs
them — the diff must be empty. The CI job runs it against the sibling bundle checkout. See
[workbench](workbench.md).

## Warming for production

The [`optimize` pipeline](optimize.md) warms the OpenAPI artifact (and the discovery snapshot)
so production serves a pre-built document with no scan. `jsonapi.openapi.public_path` can also
write static `.json`/`.yaml` for a CDN.
