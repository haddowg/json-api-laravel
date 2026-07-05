# haddowg/json-api-laravel: spec-compliant JSON:API for Laravel

`haddowg/json-api-laravel` makes [`haddowg/json-api`](https://github.com/haddowg/json-api)
idiomatic in a Laravel application. You declare a JSON:API type as a class; the package
discovers it, auto-registers the standard endpoint set, runs the request lifecycle through
one invokable controller, renders every failure on a JSON:API route as a spec-compliant
error document, and — through an **Eloquent reference data layer** — fetches and persists
your models. No controller, no operation handler, no serializer wired by hand.

It is the **Laravel twin of the [Symfony bundle](https://github.com/haddowg/json-api-symfony)**:
both build on the same framework-agnostic core, and both project a **byte-identical OpenAPI
document** for an identical domain, so a `json-api-ts` client consumes either backend
unchanged.

## The package builds on core — you will read both doc sets

There are two libraries, with a clean split of responsibilities:

- **The core library owns the JSON:API model.** `AbstractResource`, the field and relation
  DSL, the constraint vocabulary, the response value objects (VOs), the document model,
  operations, content negotiation, the exception catalogue — all framework- and
  storage-agnostic. Start with the core
  [index](https://github.com/haddowg/json-api/blob/main/docs/index.md),
  [getting-started](https://github.com/haddowg/json-api/blob/main/docs/getting-started.md),
  and [concepts](https://github.com/haddowg/json-api/blob/main/docs/concepts.md) — the
  shared mental model every page here assumes.
- **This package owns the Laravel integration.** Discovery and the service container, route
  auto-registration, the invokable controller lifecycle, route-scoped error rendering, the
  Eloquent data layer, the `DataProvider`/`DataPersister` service-provider interface (SPI),
  the always-on Laravel validator bridge, policy authorization, configuration, and
  multi-server wiring.

So these docs never re-explain a core concept — they link it. When a page touches `fields()`,
it links core [fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md) and
documents only the Laravel affordance around it.

## Requirements

| Requirement | Version | Why |
| --- | --- | --- |
| PHP | 8.3, 8.4, or 8.5 | The package uses typed class constants (8.3+). 8.3 is a hard floor. |
| Laravel | `^12.0 \|\| ^13.0` | Depends on the `illuminate/*` components, not `laravel/framework`. Laravel 11 is EOL. |
| `nyholm/psr7` + `symfony/psr-http-message-bridge` | latest | Hard runtime deps. The controller converts the Illuminate request to PSR-7 to drive core, then bridges the PSR-7 response back. |

## Install

```bash
composer require haddowg/json-api-laravel
```

Composer pulls core transitively. The service provider is auto-discovered — nothing to
register. Publishing the config and pointing discovery at your resources lives in
[install](install.md).

## A taste

A JSON:API type is a class. Extend `AbstractResource`, declare your `fields()`, and (only if
you need extras) annotate it with `#[AsJsonApiResource]`:

```php
<?php

declare(strict_types=1);

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

Drop it under `app/JsonApi/` (the default scan path) and you have `GET /api/albums`,
`GET /api/albums/{id}`, `POST /api/albums`, `PATCH /api/albums/{id}`, and
`DELETE /api/albums/{id}`, each rendering a JSON:API 1.1 document — collections, sparse
fieldsets, sorting, filtering, pagination, relationships, and validated writes — over your
Eloquent `Album` model, with no controller and no handler. The step-by-step build is in
[getting-started](getting-started.md).

## Feature highlights

- **Zero-config discovery + routing** — any `AbstractResource` under `app/JsonApi/` is
  discovered and its endpoint set auto-registered from `config/jsonapi.php`, `route:cache`-safe.
  See [routing](routing.md).
- **An Eloquent reference data layer** — zero-config fetch/persist for any resource whose
  `$type` maps to a model, with `where`/`whereHas`/`whereDoesntHave` filters, sorting,
  page/offset/cursor pagination, and SQL-push-down relationship windowing. See
  [eloquent](eloquent.md).
- **Always-on validation** — core's declared constraints become real
  `illuminate/validation` rules with real (localizable) messages, rendered as `422` with
  `source.pointer`. See [validation](validation.md).
- **Policy authorization** — the model's Gate policy is checked at each lifecycle point with
  the loaded model in hand, plus ability-name and dedicated-API-policy overrides. See
  [authorization](authorization.md).
- **Relationships** — related + relationship read and mutation endpoints, compound
  `?include` via an SPI batcher, `?withCount`, pivots, and the Relationship Queries profile.
  See [relationships](relationships.md).
- **Custom actions & atomic operations** — non-CRUD `-actions/{name}` endpoints and the
  Atomic Operations extension. See [actions](actions.md) and
  [atomic-operations](atomic-operations.md).
- **Lifecycle events & hooks** — 18 real Laravel events plus a per-resource hook trait. See
  [lifecycle](lifecycle.md) and [lifecycle-hooks](lifecycle-hooks.md).
- **OpenAPI 3.1** — an auto-generated, byte-compatible document, a Swagger UI / ReDoc
  viewer, JSON Schema exports, and an `optimize` pipeline. See [openapi](openapi.md) and
  [optimize](optimize.md).
- **A testing kit** — the `InteractsWithJsonApi` trait, JSON:API-aware `TestResponse`
  macros, and optional schema conformance. See [multi-server-and-testing](multi-server-and-testing.md).

## The example app

Every snippet in these docs is lifted from the **music-catalog workbench** — a Testbench
application (`workbench/`) that assembles the same twelve-type domain as the Symfony
example, exercised by CI-run tests so the docs cannot drift. Run it live with
`docker compose up`. See [workbench](workbench.md) and [docker](docker.md).

**Next:** [Installation →](install.md)
