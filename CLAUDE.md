# CLAUDE.md — executor playbook (json-api-laravel)

`haddowg/json-api-laravel` makes [`haddowg/json-api`](https://github.com/haddowg/json-api)
idiomatic in a Laravel application — the standard JSON:API 1.1 endpoint set over an
**Eloquent reference data layer**, with no controller, handler, or serializer wired by hand.
It is the Laravel twin of the [`haddowg/json-api-symfony`](https://github.com/haddowg/json-api-symfony)
bundle: both build on the framework-agnostic core and project a **byte-identical OpenAPI
document** for an identical domain. Core (`../json-api`) is the authority on the library's
internals — its own `CLAUDE.md` covers them.

## Gates (all green before anything lands)

```bash
composer test                              # PHPUnit — Eloquent + in-memory conformance
vendor/bin/phpstan --memory-limit=1G       # PHPStan L9 + Larastan
composer cs-check                          # PHP-CS-Fixer, PER-CS 2.0
composer byte-compat                       # OpenAPI diff vs the bundle (needs ../json-api-symfony)
```

Both data providers run in the suite — the Eloquent reference provider and an in-memory
conformance witness — so a finding stays attributable to the data layer rather than the wiring.
Existing tests are the contract: seed correct data to satisfy them, never edit a test to pass.

## Conventions

- **Namespace** `haddowg\JsonApiLaravel\`; PHP `^8.3` (8.3 / 8.4 / 8.5); Laravel `^12 || ^13`
  via the `illuminate/*` components. PHPUnit 12, PHPStan L9 + Larastan, PER-CS 2.0 (php-cs-fixer).
- **Conventional Commits** for every commit and PR title (PRs are squash-merged; release-please
  drives versioning). Mark a breaking change with `!` or a `BREAKING CHANGE:` footer. PR
  descriptions read as external-contributor prose — no "What/Why" headings, no reference to
  internal planning. Follow `~/.claude/references/commits.md` and
  `~/.claude/references/pull-requests.md`. Rebase with `--force-with-lease`.
- Record architecture decisions as ADRs under `docs/adr/` — see
  [`docs/adr/ADR-FORMAT.md`](docs/adr/ADR-FORMAT.md).

## How this package differs from the bundle

Parity with the Symfony bundle is measured by byte-compat on the *output*, not by matching its
internals. The deliberate, idiomatic-Laravel divergences: always-on validation (native
`illuminate/validation` rules) rather than opt-in; Gate/policy authorization rather than
`security:` expressions; `Rule::unique` checked pre-hydration; SQL-push-down-only relationship
windowing; polymorphic to-many on the reference provider; a `workbench/` app rather than
`examples/`; the `InteractsWithJsonApi` trait + `TestResponse` macros rather than a browser
helper; `jsonapi:*` artisan commands; and an `optimize` pipeline rather than cache warmers.

## Working with the sibling core

Core is developed as a sibling checkout at `../json-api`. When a change here needs a core
change, make it in core first (with an ADR + tests), get it green and released there, then
consume it. Prefer fixing core over working around it locally.
