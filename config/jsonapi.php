<?php

declare(strict_types=1);

use haddowg\JsonApi\Schema\JsonApiObject;

return [
    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | The absolute base URI prepended to generated links. An empty string means
    | links derive from the incoming request's scheme + host (the common case for
    | a single-origin API); set it explicitly when the API is served behind a
    | proxy or on a canonical host distinct from the request origin.
    |
    */
    'base_uri' => env('JSONAPI_BASE_URI', ''),

    /*
    |--------------------------------------------------------------------------
    | JSON:API version
    |--------------------------------------------------------------------------
    |
    | The version advertised in the top-level `jsonapi.version` member.
    |
    */
    'version' => JsonApiObject::VERSION,

    /*
    |--------------------------------------------------------------------------
    | Strict query parameters
    |--------------------------------------------------------------------------
    |
    | When true, an unrecognized top-level query-parameter family is rejected
    | with a 400 up front, so a client typo surfaces as a clean error rather than
    | being silently dropped. Set false to restore the tolerant (silent-ignore)
    | behaviour.
    |
    */
    'strict_query_parameters' => true,

    /*
    |--------------------------------------------------------------------------
    | Maximum include depth
    |--------------------------------------------------------------------------
    |
    | The default cap on relationship-include depth (hops from the primary
    | resource). A non-positive value means unlimited; a resource may override it.
    |
    */
    'max_include_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | `max_per_page` caps the client-controlled page size for the built-in page
    | paginator (a page-size DoS bound). Set to 0 to disable the built-in default
    | paginator entirely (collections then render unpaginated unless a resource
    | declares its own).
    |
    */
    'pagination' => [
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response header defaults
    |--------------------------------------------------------------------------
    |
    | Global declarative response headers, applied to every JSON:API type unless a
    | resource overrides them on its `#[AsJsonApiResource(cacheHeaders:…, deprecation:…,
    | sunset:…)]` attribute (which layers over these). `cache_headers` declares the
    | HTTP cache directives for safe (GET) reads — `max_age`/`s_maxage`/`public`/
    | `private`/`no_cache`/`must_revalidate`/`vary`, with an optional nested `operations`
    | per-read-shape override map (`collection`/`read`/`related`/`relationship`); it is
    | applied only to a successful GET, never a write or an error. `deprecation`/`sunset`/
    | `sunset_link` declare the IETF Deprecation header + RFC 8594 Sunset (+ its companion
    | Link), emitted on every response for the type. All empty/null by default (no
    | Cache-Control, no deprecation — today's behaviour).
    |
    */
    'defaults' => [
        'cache_headers' => [],
        'deprecation' => null,
        'sunset' => null,
        'sunset_link' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | The filesystem paths scanned for JSON:API capability classes (resources,
    | and — from later phases — providers/persisters and other SPI
    | implementations). `JsonApi::discover([...])` appends more paths and
    | `JsonApi::register([...])` registers explicit classes without scanning.
    |
    | `cache` is an optional path to a pre-built discovery snapshot; when the file
    | exists it is loaded instead of scanning (route:cache-safe). Null = always
    | scan live.
    |
    */
    'discovery' => [
        'paths' => [
            app_path('JsonApi'),
        ],
        'cache' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Each configured server is an independent API surface. `prefix` is the URI
    | path prefix, `middleware` the route middleware group(s), and `domain` an
    | optional host constraint. A single-API application needs only the `default`
    | server. Route names are `jsonapi.{type}.{action}` for the default server and
    | `jsonapi.{server}.{type}.{action}` for a named server.
    |
    */
    'servers' => [
        'default' => [
            'prefix' => 'api',
            'middleware' => [],
            'domain' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Atomic Operations
    |--------------------------------------------------------------------------
    |
    | The JSON:API Atomic Operations extension endpoint (opt-in; wired in a later
    | phase). `path` is the batch endpoint path.
    |
    */
    'atomic_operations' => [
        'enabled' => false,
        'path' => '/operations',
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI
    |--------------------------------------------------------------------------
    |
    | Generation + exposure of the OpenAPI 3.1 document and the standalone JSON
    | Schema documents projected from the discovered resources. The document is
    | byte-compatible with the Symfony bundle's for an identical domain, so a
    | `json-api-ts` codegen consumes either backend unchanged.
    |
    | The HTTP routes (`/docs.json`, `/schemas.json`, `/docs`) are registered only
    | when `app.debug` is true OR `expose_in_prod` is true; the CLI export commands
    | (`jsonapi:openapi:export`, `jsonapi:jsonschema:export`) are always available.
    |
    | `multi_server`: `separate` emits one document per server (+ per-server routes);
    | `combined` emits a single document unioning every server (requires
    | non-colliding types across servers).
    |
    | `enum_value_descriptions`: how backed-enum values render — `both`, `extensions`
    | (x-enum-* only), or `description` (prose only).
    |
    | `public_path`: when set, `php artisan optimize` also writes static `.json`/`.yaml`
    | files there for a web server / CDN to serve with zero PHP.
    |
    | `cache_path`: the directory the warmed artifacts are stored in (null =
    | storage/framework/cache/jsonapi-openapi).
    |
    | `describedby`: when true (default), every JSON:API response gains a top-level
    | `links.describedby` pointing at the served OpenAPI document — but only when the
    | document routes are actually reachable (the expose gate above), so it never
    | advertises a link to a document that is not served.
    |
    */
    'openapi' => [
        'enabled' => true,
        'expose_in_prod' => false,
        'multi_server' => 'separate',
        'enum_value_descriptions' => 'both',
        'public_path' => null,
        'cache_path' => null,
        'describedby' => true,

        'json' => [
            'path' => '/docs.json',
        ],

        'json_schema' => [
            'enabled' => true,
            'path' => '/schemas.json',
        ],

        'ui' => [
            'enabled' => true,
            'renderer' => 'swagger',
            'path' => '/docs',
            'cdn' => null,
        ],

        // The document `info` block. A null title falls back to `JSON:API`
        // (`JSON:API (<server>)` for a named server); a null version to `1.0.0`.
        'info' => [
            'title' => null,
            'version' => null,
            'description' => null,
            'contact' => [
                'name' => null,
                'url' => null,
                'email' => null,
            ],
            'license' => [
                'name' => null,
                'identifier' => null,
                'url' => null,
            ],
        ],

        // Advertised OAS Server objects (empty = derive one from the base URI).
        'servers' => [],

        // Document-root tag definitions (referenced-but-undefined tags are synthesized).
        'tags' => [],

        // Named security schemes + the document-level default requirement.
        'security' => [
            'schemes' => [],
            'default_requirement' => [],
        ],

        'externalDocs' => null,
    ],
];
