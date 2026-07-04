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

Conventions are inherited from the bundle unless PLAN.md records a deliberate divergence:
namespace `haddowg\JsonApiLaravel\`, PHP `^8.3`, Laravel `^12 || ^13` via `illuminate/*`
components, PHPUnit 12 + PHPStan L9 + PER-CS 2.0 (php-cs-fixer), Conventional Commits,
squash-merged PRs, release-please, ADRs under `docs/adr/`.
