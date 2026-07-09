# CLAUDE.md — executor playbook (json-api-laravel)

`haddowg/json-api-laravel` makes [`haddowg/json-api`](https://github.com/haddowg/json-api)
idiomatic in a Laravel application — everything `haddowg/json-api-symfony` does, with an
**Eloquent reference data layer**. The Symfony bundle (sibling checkout at
`../json-api-symfony`) is the parity spec; core (`../json-api`) is the authority on the
library's internals. This package is the **second pre-1.0 integration witness**: core 1.0 is
held until reads + writes are proven here, and friction is fixed in core (ADR + merged to
core `main`) rather than worked around locally.

> **Build in progress.** The agreed decision record, phase plan, and process rules live in
> `PLAN.md` at the repo root — a git-ignored local working file. Read it first in every
> session. Remove `PLAN.md` and this notice when all phases are complete.

## Current state

Phases 0–5 are built. The package is **feature-complete**: CRUD, relationships (incl. SQL
push-down windowing and polymorphic to-many on the reference provider), always-on validation,
policy authorization, the OpenAPI subtree (byte-compatible with the bundle), custom actions,
atomic operations, lifecycle events + hooks, response headers, the testing kit, and
multi-server — over dual providers (Eloquent + in-memory conformance witness).

Phase 5 (consolidation) delivered:

- **The music-catalog workbench** (`workbench/app/MusicCatalog`) — the full twelve-type domain
  matching the Symfony example, wired twice (Eloquent + in-memory) off shared `Fixtures`, in
  its own namespace so it never collides with the per-phase suites under
  `workbench/app/{JsonApi,Surface,Security,Pivot,Validation,Cursor}`.
- **The byte-compat pipeline** — `bin/normalize-openapi.php`, `bin/byte-compat.php`, the
  `composer byte-compat` script, the `byte-compat` CI job, and `tests/ByteCompat`.
- **The docs tree** (`docs/*.md`) — a Laravel-voiced mirror of the bundle doc set, plus
  `mkdocs.yml`, `mkdocs_hooks.py`, and `.github/workflows/docs.yml`. Snippets are lifted from
  the workbench; `mkdocs build --strict` is clean. (The one-off parity checklist
  `docs/parity-audit.md` served its purpose and was deleted in `db8db40` — byte-compat CI is
  the ongoing parity guarantee.)
- **The Docker demo** — `Dockerfile` + `compose.yaml` + `testbench.docker.yaml` +
  `MusicCatalogDemoServiceProvider`, running `testbench serve` over the full domain
  (`docker compose up` → `http://localhost:8080/api/albums`). Core is resolved via the VCS-repo
  trick (the path-repo symlink does not exist in a container).

**Resolved parity finding (documented, non-blocking):** the per-resource
`serializer:`/`hydrator:` override on `#[AsJsonApiResource]` is now carried (ADR 0015) — the
override params exist and are threaded through discovery into core's `Server::register()`,
alongside the standalone `#[AsJsonApiSerializer]`/`#[AsJsonApiHydrator]`. Byte-compat is
unaffected either way (the workbench also reaches both bundle cases via field closures + the
hook trait). See finding **F1** (resolved) in the parity audit.

**Remaining close-out (human-gated, do NOT do autonomously):** pin core `^1.0` once json-api#6
merges; then remove `PLAN.md` + this notice. The main session owns this final step.

## Gates (all green before any stage finishes)

```bash
composer test                              # PHPUnit — Eloquent + in-memory conformance
vendor/bin/phpstan --memory-limit=1G       # PHPStan L9 + Larastan
composer cs-check                          # PHP-CS-Fixer, PER-CS 2.0
composer byte-compat                       # OpenAPI diff vs the bundle (needs ../json-api-symfony)
```

Conventions are inherited from the bundle unless PLAN.md records a deliberate divergence:
namespace `haddowg\JsonApiLaravel\`, PHP `^8.3`, Laravel `^12 || ^13` via `illuminate/*`
components, PHPUnit 12 + PHPStan L9 + PER-CS 2.0 (php-cs-fixer), Conventional Commits,
squash-merged PRs, release-please, ADRs under `docs/adr/`.

The recorded divergences from the bundle ("parity with an asterisk") are in PLAN.md and
reconciled in the parity audit: always-on validation, policies vs security expressions,
`Rule::unique` pre-hydration, SQL-push-down-only windowing, polymorphic to-many on the
reference provider, `workbench/` vs `examples/`, trait+macros vs `JsonApiBrowser`, artisan
`jsonapi:*` vs `json-api:*`, the `optimize` pipeline vs cache warmers. Existing tests are the
contract — seed correct data to satisfy them, never edit a test to pass.
