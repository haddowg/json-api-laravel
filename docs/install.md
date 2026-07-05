# Installation

This page covers installing `haddowg/json-api-laravel`, publishing its configuration, and
the one thing worth knowing up front: **routes register themselves** from your config — the
opposite of the Symfony bundle, where you import a route type by hand.

## Requirements

| Requirement | Constraint |
| --- | --- |
| PHP | `^8.3` (8.3 / 8.4 / 8.5) |
| Laravel | `^12.0 \|\| ^13.0` (via the `illuminate/*` components) |

The hard runtime dependencies beyond Laravel are the PSR-7 bridge the controller uses:
`nyholm/psr7` and `symfony/psr-http-message-bridge`. Both are direct `require` entries, so
installing the package pulls them in.

## Install

```bash
composer require haddowg/json-api-laravel
```

Composer pulls core (`haddowg/json-api`) transitively. The service provider
(`haddowg\JsonApiLaravel\JsonApiServiceProvider`) is registered automatically through
Laravel package discovery — there is nothing to add to `bootstrap/providers.php`.

> [!NOTE]
> Until core ships a stable release, this package depends on `haddowg/json-api: dev-main`.
> If you install from source before then, add core as a VCS repository so Composer can
> resolve the development branch:
>
> ```bash
> composer config repositories.haddowg-json-api vcs https://github.com/haddowg/json-api
> ```
>
> The `docker compose up` demo and CI both use this VCS trick — see [docker](docker.md).

## Publish the configuration

The package ships a sensible default `config/jsonapi.php`; publish it to customise servers,
pagination, OpenAPI, and the discovery paths:

```bash
php artisan vendor:publish --tag=jsonapi-config
```

This writes `config/jsonapi.php`. The full tree — `base_uri`, `version`,
`strict_query_parameters`, `max_include_depth`, `pagination`, `defaults` (response
headers), `discovery`, `servers`, `atomic_operations`, and `openapi` — is documented on
[configuration](configuration.md).

## Where resources live

By default the package scans `app/JsonApi/` for any class extending core's
`AbstractResource` (and for the other capability classes — serializers, actions, providers,
persisters). Drop a resource there and it is discovered, its routes registered, and its
OpenAPI paths projected — no manual registration.

```
app/
└── JsonApi/
    ├── AlbumResource.php
    └── ArtistResource.php
```

Add more scan paths, or register a class that lives elsewhere, from a service provider's
`register()` (see [routing](routing.md) and [resources](resources.md)):

```php
use haddowg\JsonApiLaravel\Facades\JsonApi;

JsonApi::discover([app_path('Api/Resources')]);   // extra scan paths
JsonApi::register([\App\Elsewhere\ChartSerializer::class]); // an explicit class
```

## Optional dependencies

Almost everything is on by default (the Eloquent layer, validation, authorization). The one
opt-in is the opis JSON-Schema linter:

| Package | Enables |
| --- | --- |
| `opis/json-schema` | the testing kit's `SchemaConformanceTrait`, the `assertJsonApiSpecCompliant()` macro, and the optional structural document linter |

```bash
composer require --dev opis/json-schema
```

## Next

You are installed. Continue with [getting-started](getting-started.md) to build your first
endpoint end to end, or jump to [configuration](configuration.md) for the config tree in
full.
