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
| `tracks` | `ArrayList` with per-item rules; `Time`; computed `displayTitle`; a plain `belongsToMany`; a [pagination menu](pagination.md) on its collection (`MultiPaginator`: page by default, cursor or offset by `page[kind]` or a strategy-unique key) |
| `playlists` | UUID id; lifecycle hooks; API-distinct policy; per-relation security; one-model-two-types; pivot (`orderedTracks`) |
| `users` | admin-only server; `Email`/`Ip`/`ArrayHash`; write-only `password`; validation composition; `Rule::unique`; include whitelist |
| `public-profiles` | one model, two types (a curated read-only `User` view) |
| `favorites` | polymorphic to-one (`MorphTo`); `cannotBeIncluded()` |
| `libraries` | **polymorphic to-many** (`MorphToMany`) running on the reference provider — the over-parity headline |
| `genres` | client-supplied natural-key id; declarative cache headers |
| `devices` | ULID id; self-link opt-out; RFC 8594 deprecation |
| `products` | encoded (opaque) id via a codec; self-referential relation |
| `charts`, `countries` | standalone serializers (no model) + custom providers; `symfony/intl` reference data |

`albums` is served on both the `default` and `admin` servers, and a related endpoint returns
its target as primary data — so every type an album's (or a user's) related endpoints reach
is registered on both too: `artists`, `tracks`, `playlists`, `libraries`, `public-profiles`.
`users` stays admin-only, which is the point of the split; `playlists.owner` targets it
linkage-only (`withoutRelatedEndpoint()`) so the default surface never promises a `users`
endpoint it cannot serve. See [relationships](relationships.md#the-related-endpoints-target-must-be-registered-on-the-server).

## Dual-provider wiring

The domain is wired twice, over the same resources and fixtures:

- [`MusicCatalogEloquentServiceProvider`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Providers/MusicCatalogEloquentServiceProvider.php)
  maps each type to an `mc_`-prefixed Eloquent model and serves it through the reference
  [Eloquent](eloquent.md) provider/persister.
- [`MusicCatalogInMemoryServiceProvider`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Providers/MusicCatalogInMemoryServiceProvider.php)
  serves the same resources over seeded in-memory POPO providers — the **conformance witness**.

Both read the identical [`Fixtures`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Support/Fixtures.php),
so the dual-provider suite proves every type is served identically by both arms. It lives in
its own namespace with its own wiring, so it never collides with the per-feature test suites
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

The workbench exists to prove byte-compatibility: for this identical domain, the projected OpenAPI
document is byte-identical to the Symfony bundle's. Run the diff locally:

```bash
composer byte-compat
```

It exports this package's `default` + `admin` documents, normalizes `info`/`servers[].url`, and
diffs them against the bundle's — the diff must be empty. See [openapi](openapi.md).

The bundle's side is not booted from a checkout. Each bundle release publishes its music-catalog
documents as the npm package `@haddowg/json-api-symfony-specs` on GitHub Packages, and
`package.json` pins one exact version of it as a dev dependency, so `npm ci` installs it into
`node_modules/`. Reading GitHub Packages needs a token, even for a public package. Locally:

```bash
gh auth refresh -s read:packages      # once
export NODE_AUTH_TOKEN="$(gh auth token)"
export NPM_CONFIG_USERCONFIG="$(.github/scripts/npm-auth)"
npm ci
```

The package's `versions.json` names the `haddowg/json-api` version that projected it, and
`composer byte-compat` fails before diffing unless that is the version installed here: documents
from two different cores would differ for reasons that have nothing to do with this package. A
leading `v` is ignored, and a development version (`dev-main`) passes with a warning, because a
branch name doesn't pin a commit. The `byte-compat` CI job installs the core the documents name
before it runs, so a core release doesn't fail it while the pinned bundle release still uses the
previous core.

To diff against bundle code that isn't published, export its documents from a bundle checkout
and point the check at them:

```bash
(cd ../json-api-symfony && php bin/export-specs.php /tmp/bundle-specs)
JSON_API_SYMFONY_SPECS_PATH=/tmp/bundle-specs composer byte-compat
```

Both checkouts must then resolve the same core, for example by symlinking the same sibling
`../json-api`.

The two conformance suites also share response documents
(`tests/Fixtures/IdentifiedMorphMany/documents` here). The package doesn't carry them, so
`composer byte-compat` diffs them only when it finds a bundle checkout at `../json-api-symfony`
(or `JSON_API_SYMFONY_PATH`), and skips them otherwise, which includes CI.

### Pinning the bundle documents

Pin a bundle release with an exact version, and commit `package.json` and `package-lock.json`:

```bash
npm i -DE @haddowg/json-api-symfony-specs@<release>
```

While a change waits for a bundle pull request, pin that pull request's prerelease instead,
`0.0.0-pr.<number>.<sha7>`, which the bundle's CI names in a comment on it. Each prerelease is
deleted when its pull request closes, so re-pin to the bundle release once it's out.

`bin/check-specs-pin` (`npm run check:specs-pin`) guards the pin: it must be an exact version in
`devDependencies`, `package-lock.json` must lock the same version (from GitHub Packages when it
records where from), and the registry must have it. A prerelease fails unless the guard is passed
`--allow-prerelease`. CI passes it on feature pull requests, and not on `main` or on a
release-please pull request, so a feature pull request can build against a bundle prerelease but
a release can't ship one.

## Running it

```bash
composer test                         # the whole suite, both providers
vendor/bin/testbench serve            # serve the DEFAULT workbench suite at /api/artists
docker compose up                     # serve the FULL music-catalog domain — see docker.md
```

To serve the full twelve-type domain over HTTP, use the [Docker demo](docker.md) — it swaps in
the music-catalog Testbench config so `testbench serve` boots the whole domain instead of the
default suite.
