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
[configuration](configuration.md#openapi). Every JSON:API response can carry a top-level
`links.describedby` pointing at the served document (`openapi.describedby`), emitted only when
the document routes are actually reachable.

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
[workbench](workbench.md) and the
[parity audit](https://github.com/haddowg/json-api-laravel/blob/main/docs/parity-audit.md).

## Warming for production

The [`optimize` pipeline](optimize.md) warms the OpenAPI artifact (and the discovery snapshot)
so production serves a pre-built document with no scan. `jsonapi.openapi.public_path` can also
write static `.json`/`.yaml` for a CDN.
