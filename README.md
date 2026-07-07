# json-api-laravel

[![CI](https://github.com/haddowg/json-api-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/haddowg/json-api-laravel/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/haddowg/json-api-laravel.svg)](https://packagist.org/packages/haddowg/json-api-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/haddowg/json-api-laravel.svg)](https://packagist.org/packages/haddowg/json-api-laravel)
[![License](https://img.shields.io/packagist/l/haddowg/json-api-laravel.svg)](LICENSE)

> **Part of the [jsonapi.rest](https://jsonapi.rest) suite** — a complete, spec-compliant
> JSON:API 1.1 stack for PHP: a framework-agnostic [core](https://github.com/haddowg/json-api),
> a [Symfony bundle](https://github.com/haddowg/json-api-symfony), this **Laravel package**, and
> a typed TypeScript client, bound together by one conformance-tested OpenAPI 3.1 contract.

A Laravel package that makes [`haddowg/json-api`](https://github.com/haddowg/json-api)
idiomatic in a Laravel application: declare a JSON:API type as a class and get the standard
endpoint set — spec-compliant JSON:API 1.1 documents, content negotiation, validation, policy
authorization, and an **Eloquent data layer** — with no controller, handler, or serializer
wired by hand.

It is the Laravel twin of the [Symfony bundle](https://github.com/haddowg/json-api-symfony):
both build on the same framework-agnostic core and project a **byte-identical OpenAPI document**
for an identical domain, so a client generator consumes either backend unchanged.

## Requirements

- PHP `^8.3` (8.3 / 8.4 / 8.5)
- Laravel `^12.0 || ^13.0` (via the `illuminate/*` components)

## Install

```bash
composer require haddowg/json-api-laravel
php artisan vendor:publish --tag=jsonapi-config   # optional — customise servers, pagination, OpenAPI
```

The service provider is auto-discovered and core is pulled in transitively — there is nothing
to register by hand.

## Quickstart

Drop a resource under `app/JsonApi/`:

```php
<?php

namespace App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\HasMany;

final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            HasMany::make('tracks', 'tracks'),
        ];
    }
}
```

That is the whole integration. You now have, over your Eloquent `Album` model:

```
GET    /api/albums          GET /api/albums/{id}
POST   /api/albums          PATCH /api/albums/{id}          DELETE /api/albums/{id}
GET    /api/albums/{id}/tracks    GET /api/albums/{id}/relationships/tracks
```

— collections, sparse fieldsets, sorting, filtering, pagination, relationships, `?include`,
and validated writes, each a JSON:API 1.1 document.

## Highlights

- **Zero-config discovery + routing** — auto-registered, `route:cache`-safe.
- **Eloquent reference data layer** — filters, sorting, page/offset/cursor pagination, and
  SQL-push-down relationship windowing; or bring your own via the `DataProvider`/`DataPersister`
  SPI.
- **Always-on validation** — core's constraints become real `illuminate/validation` rules →
  `422` with `source.pointer`.
- **Policy authorization** — the model's Gate policy, ability-name overrides, or a dedicated
  API-policy class.
- **Relationships** — related + relationship read/mutation endpoints, `?include`,
  `?withCount`, pivots, the Relationship Queries profile, and polymorphic to-many.
- **Custom actions & atomic operations**, **lifecycle events + hooks**, **response headers**,
  **multi-server**.
- **OpenAPI 3.1** — an auto-generated, byte-compatible document, a Swagger UI / ReDoc viewer,
  JSON Schema exports, and an `optimize` pipeline.
- **A testing kit** — the `InteractsWithJsonApi` trait and JSON:API-aware `TestResponse`
  macros.

## Try the demo

```bash
docker compose up   # then open http://localhost:8080/api/albums and http://localhost:8080/docs
```

Serves the full twelve-type music-catalog example over HTTP. See the
[Docker guide](https://haddowg.github.io/json-api-laravel/docker/).

## Documentation

Full documentation: **[haddowg.github.io/json-api-laravel](https://haddowg.github.io/json-api-laravel/)**

Core concepts (fields, relations, constraints, response value objects) live in the
[core documentation](https://haddowg.github.io/json-api/).

## Contributing & development

```bash
composer test        # PHPUnit (both the Eloquent and in-memory providers)
composer phpstan     # PHPStan level 9 + Larastan
composer cs-check    # PHP-CS-Fixer (PER-CS 2.0)
composer byte-compat # diff the OpenAPI document against the Symfony bundle's
```

## License

Released under the [MIT License](LICENSE).
