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
];
