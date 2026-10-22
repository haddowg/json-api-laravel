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

An optional `base_uri` per server overrides the global `base_uri`. Omit it to inherit. It is
used verbatim, so include the prefix.

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
| `describedby` | add a top-level `links.describedby` to every response (only when the document routes are reachable) |
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
