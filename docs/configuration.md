# Configuration

All configuration lives in `config/jsonapi.php` (publish it with
`php artisan vendor:publish --tag=jsonapi-config`). This page walks the tree; the file itself
carries inline comments for every key. Where a key configures a core `Server`, the semantics
are core's — this page documents the Laravel-side shape.

## `base_uri`

```php
'base_uri' => env('JSONAPI_BASE_URI', ''),
```

The absolute base prepended to generated links. An **empty string** (the default) derives
links from the incoming request's scheme + host — the right choice for a single-origin API.
Set it to pin a canonical host when the API sits behind a proxy.

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
