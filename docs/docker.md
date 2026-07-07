# Run the demo with Docker

The repository ships a `Dockerfile` + `compose.yaml` that serve the full
[music-catalog workbench](workbench.md) over HTTP — a self-contained demo answering real
JSON:API requests, parity with the Symfony example's `docker compose up`.

## Quick start

From the repository root:

```bash
docker compose up
```

Then open — or `curl` with the JSON:API `Accept` header:

```bash
curl -H 'Accept: application/vnd.api+json' http://localhost:8080/api/albums
curl -H 'Accept: application/vnd.api+json' http://localhost:8080/admin/albums   # the admin server
open http://localhost:8080/docs                                                  # the Swagger UI
open http://localhost:8080/docs.json                                             # the OpenAPI document
```

The container serves the whole twelve-type domain: the multi-server witness (`albums` on both
`/api` and `/admin`, `users` admin-only), declarative cache headers on `genres`, the encoded
ids on `products`, the polymorphic reads, and every id strategy.

## How it works

- **Base image** — `php:8.3-cli` with `intl`, `zip`, and `pdo_sqlite`. The container runs
  `vendor/bin/testbench serve` (the PHP dev server), not FrankenPHP.
- **Dependency resolution** — the build runs `composer update` inside the image to resolve the
  full dependency tree (including core) at build time. Pass `--build-arg GITHUB_TOKEN=…` to lift
  anonymous GitHub API rate limits during the fetch.
- **Full-domain wiring** — the build copies
  [`testbench.docker.yaml`](https://github.com/haddowg/json-api-laravel/blob/main/testbench.docker.yaml)
  over `testbench.yaml` inside the image, so `testbench serve` boots the music-catalog
  providers (and `CatalogConfig`) instead of the default workbench suite — the two must never
  co-register (they reuse the same type names).
- **Database** — a file-backed SQLite at `/app/database/demo.sqlite`, migrated and seeded at
  build time from the shared fixtures, so writes persist across the dev server's per-request
  boots. It lives in the image's writable layer, so the demo "resets" on `docker run` again.

## Without compose

```bash
docker build -t json-api-laravel-demo .
docker run --rm -p 8080:80 json-api-laravel-demo
```

## Try a write

The demo is fully writable (a file-backed DB). Create a genre (a client-supplied natural key):

```bash
curl -X POST http://localhost:8080/api/genres \
  -H 'Accept: application/vnd.api+json' \
  -H 'Content-Type: application/vnd.api+json' \
  -d '{"data":{"type":"genres","id":"ambient","attributes":{"name":"Ambient"}}}'
```

Recreate the container to reset the data.
