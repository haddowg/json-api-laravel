# Optimize (production warm-up)

For production, warm the package's caches and validate deployability with the `optimize`
pipeline — the Laravel-idiomatic replacement for the Symfony bundle's cache warmers (PLAN
decision 11). It hooks into `php artisan optimize`, so it runs as part of your normal deploy.

## The commands

```bash
php artisan jsonapi:optimize   # validate servability + warm the OpenAPI/discovery artifacts
php artisan jsonapi:clear      # remove the warmed artifacts
```

Both are wired into the framework pipeline via `optimizes()`:

- `php artisan optimize` runs `jsonapi:optimize`;
- `php artisan optimize:clear` runs `jsonapi:clear`.

## What `jsonapi:optimize` does

Three phases, mirroring the bundle's two warmers plus a deploy-time safety check:

1. **Servability validation (mandatory).** Eagerly checks that every discovered resource is
   actually serveable — sortable/filterable columns resolve against the table/casts, relation
   methods exist, morph maps are registered. **A problem fails the command** (a non-zero exit),
   so a mis-declared resource fails the *deploy*, not a runtime `500`.
2. **Discovery snapshot (opt-in).** When `jsonapi.discovery.cache` names a path, it writes the
   `var_export`-able discovery snapshot there, so route registration and OpenAPI projection
   become a pure function of the cache — this is what makes `route:cache` safe (see
   [routing](routing.md#route-cache-safety)).
3. **Artifact warming (optional, non-fatal).** Pre-builds the OpenAPI document + JSON Schema
   artifacts so the `/docs.json` / `/schemas.json` controllers serve a warmed document. A
   failure here is a warning, never fatal.

## Development

In development nothing is cached: the discovery scan and servability checks run lazily on first
boot, so a new resource is picked up immediately. You only run `jsonapi:optimize` when
preparing a production build (typically right before `route:cache` / `config:cache`).

## A typical deploy

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize        # config + route + view + jsonapi:optimize
```

`optimize` runs `jsonapi:optimize` for you; run it before `route:cache` so the cached routes
read the warmed discovery snapshot. To serve the OpenAPI document as static files from a CDN,
set `jsonapi.openapi.public_path` and the warm step writes `.json`/`.yaml` there — see
[openapi](openapi.md) and [configuration](configuration.md#openapi).
