# Configuration

All configuration lives in `config/jsonapi.php` (publish it with
`php artisan vendor:publish --tag=jsonapi-config`). This page walks the tree; the file itself
carries inline comments for every key. Where a key configures a core `Server`, the semantics
are core's — this page documents the Laravel-side shape.

## `base_uri`

```php
'base_uri' => env('JSONAPI_BASE_URI', ''),
```

The base prepended to every generated link. **Empty (the default): links are absolute,
built from the request's scheme + host plus the `prefix` of the server whose route matched**,
so each tenant or host gets correct links with no hardcoded host. Set a value to pin a fixed
canonical base regardless of the request, for example behind a proxy; it must then include
the server's prefix itself (see [prefixes and the base](#prefixes-and-the-base)). Trailing
slashes are tolerated. A server's own `base_uri` overrides this one.

## `output_timezone`

```php
'output_timezone' => env('JSONAPI_OUTPUT_TIMEZONE'),   // null
```

The zone every `DateTime` attribute is converted into before it is formatted. Set it when
your models carry local times but the API should answer in one zone:

```php
'output_timezone' => 'UTC',
```

A `releasedAt` held as `2024-06-01 20:00` in Paris then renders `2024-06-01T18:00:00+00:00`
instead of `2024-06-01T20:00:00+02:00`. It applies to primary data, `included`, the related
endpoints, attributes flattened with `on()`, `DateTime` children of a composite attribute,
and `DateTime` pivot fields in `meta.pivot` (see below).

`Date` and `Time` attributes are left alone. A calendar date held as midnight in Paris
falls on the previous evening in UTC, so converting it would render the wrong day, and a
time of day has no date to convert against. A field that calls `outputTimezone()` itself
keeps its own zone, and that is also the way to convert a `Date` or `Time` that really
should move. The rules are core's; see
[output timezone](https://github.com/haddowg/json-api/blob/main/docs/server.md#output-timezone)
and [timezones on the way in and out](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#timezones-on-the-way-in-and-out).

`null` (the default) or `''` renders each value in the zone it carries, as before. The value
must be an identifier PHP's `\DateTimeZone` accepts, such as `UTC` or `Europe/London`;
anything else fails the app's boot with an `\InvalidArgumentException` that names the key.
A server's own `output_timezone` overrides this one, see [`servers`](#servers).

A `DateTime` pivot field in `meta.pivot` follows the same rule as an attribute. The Eloquent
provider reads a date column the pivot model doesn't cast the way a `datetime` cast would, in
the app's timezone, and every pivot date renders through its field's format (see
[pivot data](relationships.md#pivot-belongstomany-data)).

A hand-written serializer formats its own values and is not affected.

## `link_objects`

```php
'link_objects' => true,
```

Whether the links the server builds (resource, relationship, top-level `self`, pagination
and action links) render as JSON:API link objects, `{"href": "https://music.example/api/albums/1"}`,
which can carry a [`describedby`](openapi.md#links-and-describedby) into the OpenAPI
document. `false` renders bare URL strings with no `describedby`. A server's own
`link_objects` overrides it, so one server can keep string links for a client that predates
link objects while the rest render objects:

```php
'servers' => [
    'default' => ['prefix' => 'api'],
    'legacy' => ['prefix' => 'legacy', 'link_objects' => false],
],
```

Anything other than `true`, `false` or `null` fails the app's boot with an
`\InvalidArgumentException` that names the key.

## `reject_read_only_attributes`

```php
'reject_read_only_attributes' => false,
```

By default a create or update ignores any attribute the client cannot write in that request:
a `readOnly()` or computed field, or one whose `readOnly(fn …)` predicate holds. `true`
refuses the whole body instead, with one `403` `ATTRIBUTE_WRITE_PROHIBITED` error per such
attribute, pointing at `/data/attributes/<name>`. The check runs before validation, so a body
that also breaks a validation rule still gets the `403`. It covers creates, updates, atomic
operations and the input document of a custom action. The OpenAPI error catalogue lists the
code only for a server that refuses.

A server's own `reject_read_only_attributes` overrides the global value:

```php
'servers' => [
    'default' => ['prefix' => 'api'],
    'strict' => ['prefix' => 'strict', 'reject_read_only_attributes' => true],
],
```

Anything other than `true`, `false` or `null` fails the boot, as `link_objects` does.

## `version`

```php
'version' => JsonApiObject::VERSION,   // '1.1'
```

The version advertised in the top-level `jsonapi.version` member. Most apps leave the
default.

## `strict_query_parameters`

```php
'strict_query_parameters' => true,
```

When `true` (default), an unrecognized top-level query-parameter family is rejected with a
`400`, so a client typo surfaces cleanly instead of being silently dropped. Set `false` for
the tolerant (silent-ignore) behaviour.

## `max_include_depth`

```php
'max_include_depth' => 3,
```

The default cap on `?include` depth (hops from the primary resource). A non-positive value
means unlimited; a resource overrides it with `getMaxIncludeDepth()`. A request exceeding the
cap is a `400`.

## `pagination`

```php
'pagination' => ['max_per_page' => 100],
```

`max_per_page` clamps the client-controlled page size for the built-in page paginator (a
page-size DoS bound — the request stays `200`, the size is just capped). Set `0` to disable
the built-in default paginator (collections then render unpaginated unless a resource
declares its own). See [pagination](pagination.md).

## `profiles`

```php
'profiles' => [\haddowg\JsonApi\Pagination\CursorPaginationProfile::class],
```

Additional JSON:API profiles registered on every server (class-strings, resolved through the
container), on top of the built-in Countable and Relationship Queries registrations. The
canonical use is core's `CursorPaginationProfile`, so cursor-paginated responses advertise
the published cursor-pagination profile — see
[pagination](pagination.md#advertising-the-cursor-pagination-profile). Defaults to `[]`.

## `defaults` (response headers)

```php
'defaults' => [
    'cache_headers' => [],
    'deprecation'   => null,
    'sunset'        => null,
    'sunset_link'   => null,
],
```

Global declarative response headers, layered under a resource's own
`#[AsJsonApiResource(cacheHeaders:…, deprecation:…, sunset:…)]`. `cache_headers` declares
`Cache-Control`/`Vary` directives applied only to a successful `GET`;
`deprecation`/`sunset`/`sunset_link` declare the IETF Deprecation + RFC 8594 Sunset headers
emitted on every response. See [resources](resources.md#response-headers).

## `discovery`

```php
'discovery' => [
    'paths' => [app_path('JsonApi')],
    'cache' => null,
],
```

`paths` are the directories scanned for capability classes (resources, serializers, actions,
providers, persisters). Append more with `JsonApi::discover([...])`. `cache` is an optional
path to a pre-built discovery snapshot; when the file exists it is loaded instead of scanning
(`route:cache`-safe). The [`optimize`](optimize.md) pipeline writes it for production.

## `eloquent`

```php
'eloquent' => [
    'model_namespace' => 'App\\Models',
],
```

`model_namespace` is where the convention tier of the
[resource→model resolution](eloquent.md#the-model-map-three-tiers) guesses: the kebab/plural
type, singularized and studly-cased under this namespace (`albums` → `App\Models\Album`),
claimed only when that class exists and is an Eloquent model. The package auto-registers the
reference Eloquent provider/persister pair for every type the convention or a
`#[AsJsonApiResource(model: …)]` declaration maps; explicit `JsonApi::provider()`/
`persister()` wiring always shadows it. Set to `null` to disable the convention tier
(declared models still map).

## `servers`

```php
'servers' => [
    'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
],
```

Each server is an independent API surface with its own URI `prefix`, route `middleware`, and
optional `domain` host constraint. A single-API app needs only `default`. A named server adds
its name to route names and to the OpenAPI path set. The music-catalog example adds an
`admin` server:

```php
'servers' => [
    'default' => ['prefix' => 'api',   'middleware' => [], 'domain' => null],
    'admin'   => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
],
```

A resource joins a server with `#[AsJsonApiResource(server: 'admin')]` or
`server: ['default', 'admin']`. See [multi-server-and-testing](multi-server-and-testing.md).

Six optional keys per server:

| Key | What it does |
| --- | --- |
| `base_uri` | This server's base, overriding the global `base_uri`. Omit it to inherit. Used verbatim, so include the prefix. |
| `output_timezone` | The zone this server's `DateTime` attributes render in, overriding the global [`output_timezone`](#output_timezone). Omit it or set `null` to inherit; `''` turns conversion off for this server. |
| `link_objects` | Whether this server renders its built links as link objects, overriding the global [`link_objects`](#link_objects). Omit it or set `null` to inherit. |
| `reject_read_only_attributes` | Whether this server refuses a write body that sends a read-only attribute, overriding the global [`reject_read_only_attributes`](#reject_read_only_attributes). Omit it or set `null` to inherit. |
| `openapi_examples` | Whether this server's OpenAPI document carries generated [operation examples](openapi.md#operation-examples), overriding `openapi.examples`. Omit it or set `null` to inherit. |
| `route_defaults` | Route defaults set on every route of the server, such as `['tenant' => 'acme']` for a `{tenant}/api` prefix. The OpenAPI `servers` entry uses it as the variable's default. |

### Prefixes and the base

With an empty `base_uri`, links follow the prefix. On every request the package bases the
links on the request origin plus the matched route's prefix, so a request to
`https://tenant.example/api/albums/1` renders `https://tenant.example/api/albums/1` as both
the resource and the document `self`, and pagination links read
`https://tenant.example/api/albums?page[number]=2…` with the prefix once. Relationship,
related, included and error links, and the `Location` of a created resource, use the same
base. Each server follows its own prefix, so `default` under `api` and `admin` under
`admin` each link under their own.

A prefix with route parameters (`'prefix' => '{tenant}/api'`) works too: the parameters
come from the matched route, so `/acme/api/albums/1` links under `/acme/api`. An app served
below the web root (`https://example.test/app/index.php/api/…`) adds its base path the same
way. The prefix comes from the matched route, so a server placed by hand with
`Route::jsonApi()` inside your own `Route::prefix('v2')` group follows `v2` as well (see
[routing](routing.md#manual-placement)).

A `domain` on a server doesn't change the base. When a route with a domain matched, the
request host is that domain, so the origin already carries it.

A configured `base_uri` is used as it is, so it has to include the prefix:

```php
'base_uri' => 'https://api.example/api',
'servers' => [
    'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
    'admin'   => ['prefix' => 'admin', 'middleware' => [], 'domain' => null, 'base_uri' => 'https://api.example/admin'],
],
```

Resource links append the server-relative path to that base. Document `self` and
pagination links reuse the request path, which already starts with `/api`, so core drops
the base's own path from the front of it and the prefix appears once. A configured base
without the prefix (`https://api.example` for routes under `api`) renders resource links
that miss the prefix.

The OpenAPI document advertises the same prefix as a relative `servers` URL (`/api`); see
[OpenAPI](openapi.md#the-servers-list).

## `atomic_operations`

```php
'atomic_operations' => ['enabled' => false, 'path' => '/operations'],
```

The JSON:API Atomic Operations extension endpoint (opt-in). See
[atomic-operations](atomic-operations.md).

## `openapi`

The OpenAPI 3.1 generation + exposure block. Highlights:

| Key | Purpose |
| --- | --- |
| `enabled` | master switch for generation |
| `expose_in_prod` | serve the `/docs.json`, `/schemas.json`, `/docs` routes when `app.debug` is false |
| `multi_server` | `separate` (one document per server) or `combined` (one union document) |
| `enum_value_descriptions` | how backed-enum values render — `both` / `extensions` / `description` |
| `public_path` | when set, `optimize` also writes static `.json`/`.yaml` for a CDN |
| `describedby` | give each built link object and each document a `describedby` naming its operation in the served document (only when a route serves the document, or `document_url` is set); see [links and `describedby`](openapi.md#links-and-describedby) |
| `document_url` | the URL `describedby` points into, used as given with `{server}` replaced by the server name; null uses the path of the route serving the document |
| `cache_control` | the `Cache-Control` header for `/docs.json` and `/schemas.json` (null = none of its own, so the response stays private); see [caching at the edge](openapi.md#caching-at-the-edge) |
| `ui.enabled` / `ui.renderer` / `ui.path` | serve the viewer page, pick `swagger` (default) or `redoc`, and mount it (default `/docs`) |
| `ui.cdn` | the directory the viewer's assets load from: the `swagger-ui-dist` root, or ReDoc's `bundles/` directory holding `redoc.standalone.js` (null = the pinned jsDelivr URL) |
| `ui.redoc_version` | the exact ReDoc release the `redoc` renderer loads (default `3.0.1`); a 2.x release such as `2.5.4` switches to the classic global script, and anything else fails the boot; see [the viewer](openapi.md#the-viewer) |
| `ui.redoc_telemetry` | let ReDoc 3.x send its usage telemetry (default `false`, which renders `disable-telemetry="true"` on the `<redoc>` tag) |
| `serve_allowed_classes` | classes, beyond core's OpenAPI value objects, that a document's example, default or extension values may hold and still be served from the serialized copy (default `[]`); see [per-request customisation](openapi.md#per-request-customisation) |
| `examples` | give each operation's request and success responses, and each shared error response, an example built from the declared field and error-code examples (default `true`); a server's own `openapi_examples` overrides it; see [operation examples](openapi.md#operation-examples) |
| `info` / `servers` / `tags` / `security` | the document `info`, advertised OAS servers, tag definitions, and security schemes + default requirement |

The docs routes are gated behind `app.debug || openapi.expose_in_prod`; the CLI exporters
(`jsonapi:openapi:export`, `jsonapi:jsonschema:export`) are always available. The
music-catalog example fills `info`/`tags`/`security` to project a document byte-compatible
with the Symfony bundle's — see [openapi](openapi.md).

## Reading config at runtime

Anywhere you need a value, use Laravel's `config()`:

```php
config('jsonapi.max_include_depth');
config('jsonapi.servers.admin.prefix');
```
