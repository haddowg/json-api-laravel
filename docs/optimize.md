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
   methods exist (the method checked is the one the runtime reads, so a `storedAs()` alias is
   honoured, and a relation whose value comes from an `extractUsing()`/`serializeUsing()`
   closure needs no model method at all), morph maps are registered. **A problem fails the
   command** (a non-zero exit), so a mis-declared resource fails the *deploy*, not a runtime
   `500`.
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

## Long-lived workers (Octane and queue workers)

The package binds almost everything as a **singleton** because that is the honest lifetime:
under plain FPM the container lives for one request anyway, and the heavy pieces — discovery,
the per-server core `Server` (memoized in the `ServerRegistry`), the authorization/action/
response-header projections, the OpenAPI factories — are pure functions of your config and
resource declarations, so a long-lived worker *wants* them warm. Under Octane (or a queue
worker dispatching JSON:API operations) the container survives across requests, so the
question is which singletons carry per-request state and how they stay clean:

- **The relationship seam holders** (`RequestScopedRelationshipCount`,
  `RequestScopedRelationshipPagination`, `RequestScopedRelationshipLinkage` — the swappable
  values behind the memoized `Server`'s [`?withCount`](relationships.md#withcount-the-countable-profile)
  and [Relationship Queries](relationships.md#the-relationship-queries-profile) seams) are
  stateful, but the operation handler clears all three at the very start of **every**
  dispatch and re-installs the current read's backing — the Laravel-side equivalent of the
  Symfony bundle twin's `kernel.reset`. Nothing to do; a `scoped()` rebind would in fact be
  ineffective, because the memoized `Server` and the singleton handler capture the instances
  at construction.
- **`WriteTransactionContext`** (the [atomic-operations](atomic-operations.md#the-transaction-and-deferred-work)
  post-commit hook queue) is a deliberate singleton — its only consumer is the singleton
  operation handler — and the executor deactivates it in a `finally` on both the commit and
  rollback paths, so no batch leaves it active. If you want belt-and-braces against a worker
  that somehow skips that `finally` (a fatal error mid-batch on a worker Octane then reuses),
  clear it on the request boundary:

    ```php
    // AppServiceProvider::boot(), only when Octane is installed
    use haddowg\JsonApiLaravel\DataPersister\WriteTransactionContext;

    Event::listen(\Laravel\Octane\Events\RequestReceived::class, function (): void {
        app(WriteTransactionContext::class)->reset();
    });
    ```

- **`InMemorySnapshotCoordinator`** (the in-memory provider's test-isolation snapshot holder)
  is bound `scoped()`, and Laravel flushes scoped instances at the start of each Octane
  request and each queue job — no action needed.
- **Your own providers and persisters** are resolved once into the singleton registries, so
  they live for the worker's lifetime too. Keep them stateless like the built-in Eloquent
  pair, or reset any per-request state yourself — see
  [custom data providers](custom-data-providers.md).

Queue workers get the same posture for free: the per-dispatch clears run per operation, and
a worker processes one job at a time.
