# The music-catalog example (workbench)

Every snippet in these docs is lifted from a real, CI-run application: the **music-catalog
workbench**. It lives under `workbench/` (a Testbench convention — the docs still call it "the
example"), assembles the same twelve-type domain as the
[Symfony example](https://github.com/haddowg/json-api-symfony), and is exercised by tests so
the docs cannot drift. Browse it at
[`workbench/app/MusicCatalog`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog).

## The domain

Twelve types, each chosen to witness a distinct capability:

| Type | Witnesses |
| --- | --- |
| `artists` | store-provided id; computed `trackCount`; public reads (`abilities: false`); full-text `filter[q]` |
| `albums` | multi-server (default + admin); backed-enum `status`; JSON `Map`; directional `CompareField`; relation-scoped filters; default include; the three actions |
| `tracks` | `ArrayList` with per-item rules; `Time`; computed `displayTitle`; a plain `belongsToMany` |
| `playlists` | UUID id; lifecycle hooks; API-distinct policy; per-relation security; one-model-two-types; pivot (`orderedTracks`) |
| `users` | admin-only server; `Email`/`Ip`/`ArrayHash`; write-only `password`; validation composition; `Rule::unique`; include whitelist |
| `public-profiles` | one model, two types (a curated read-only `User` view) |
| `favorites` | polymorphic to-one (`MorphTo`); `cannotBeIncluded()` |
| `libraries` | **polymorphic to-many** (`MorphToMany`) running on the reference provider — the over-parity headline |
| `genres` | client-supplied natural-key id; declarative cache headers |
| `devices` | ULID id; self-link opt-out; RFC 8594 deprecation |
| `products` | encoded (opaque) id via a codec; self-referential relation |
| `charts`, `countries` | standalone serializers (no model) + custom providers; `symfony/intl` reference data |

## Dual-provider wiring

The domain is wired twice, over the same resources and fixtures:

- [`MusicCatalogEloquentServiceProvider`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Providers/MusicCatalogEloquentServiceProvider.php)
  maps each type to an `mc_`-prefixed Eloquent model and serves it through the reference
  [Eloquent](eloquent.md) provider/persister.
- [`MusicCatalogInMemoryServiceProvider`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Providers/MusicCatalogInMemoryServiceProvider.php)
  serves the same resources over seeded in-memory POPO providers — the **conformance witness**.

Both read the identical [`Fixtures`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Support/Fixtures.php),
so the dual-provider suite proves every type is served identically by both arms. It lives in
its own namespace with its own wiring, so it never collides with the per-phase test suites
under `workbench/app/{JsonApi,Surface,Security,Pivot,Validation,Cursor}`.

Both arms also register the cross-cutting
[`AuditLogSubscriber`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Listeners/AuditLogSubscriber.php)
— the [lifecycle-events](lifecycle.md) worked example (an audit trail on every committed
write plus an `X-Read-Only` gate), runtime-only so the projected OpenAPI document is
untouched.

## Configuration

[`CatalogConfig`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Support/CatalogConfig.php)
is the single source of truth for the `jsonapi.*` config — the exact Laravel translation of
the Symfony example's `json_api.yaml` (base URI, the two servers, OpenAPI info/tags/security,
`max_include_depth: 2`, `pagination.max_per_page`, the atomic endpoint). The byte-compat
export, the `docker compose up` demo, and these docs all apply it, so they share one config.

## Byte-compatibility

The workbench exists to prove decision 11: for this identical domain, the projected OpenAPI
document is byte-identical to the Symfony bundle's. Run the diff locally:

```bash
composer byte-compat
```

It exports both twins' `default` + `admin` documents, normalizes `info`/`servers[].url`, and
diffs — the diff must be empty. The `byte-compat` CI job runs it against a fresh sibling bundle
checkout, guarding that both trees resolve the same core commit. See [openapi](openapi.md).

## Running it

```bash
composer test                         # the whole suite, both providers
vendor/bin/testbench serve            # serve the DEFAULT workbench suite at /api/artists
docker compose up                     # serve the FULL music-catalog domain — see docker.md
```

To serve the full twelve-type domain over HTTP, use the [Docker demo](docker.md) — it swaps in
the music-catalog Testbench config so `testbench serve` boots the whole domain instead of the
default suite.
