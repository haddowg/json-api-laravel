# CLAUDE.md — executor playbook (json-api-laravel)

`haddowg/json-api-laravel` makes [`haddowg/json-api`](https://github.com/haddowg/json-api)
idiomatic in a Laravel application — the standard JSON:API 1.1 endpoint set over an
**Eloquent reference data layer**, with no controller, handler, or serializer wired by hand.
It is the Laravel twin of the [`haddowg/json-api-symfony`](https://github.com/haddowg/json-api-symfony)
bundle: both build on the framework-agnostic core and project a **byte-identical OpenAPI
document** for an identical domain. Core (`../json-api`) is the authority on the library's
internals — its own `CLAUDE.md` covers them.

[`CONTEXT.md`](CONTEXT.md) is the glossary of this package's own terms (core's defines the rest),
and [`docs/adr/`](docs/adr/) records the decisions that would surprise a reader of the code.

## Gates (all green before anything lands)

```bash
composer test                              # PHPUnit — Eloquent + in-memory conformance
vendor/bin/phpstan --memory-limit=1G       # PHPStan L9 + Larastan
composer cs-check                          # PHP-CS-Fixer, PER-CS 2.0
composer byte-compat                       # OpenAPI diff vs the bundle's published documents (npm ci)
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
- **Don't record generator-contract history.** `info.x-generator.contract` is a compatibility
  check for the code generators, not a changelog. When a core release bumps it, document the new
  behaviour as current behaviour; never write which contract or release introduced it ("from
  contract 17…", "every document now carries 18") in `docs/`, this file or a code comment. The
  changelogs record when it arrived.

## How this package differs from the bundle

Parity with the Symfony bundle is measured by byte-compat on the *output*, not by matching its
internals. The deliberate, idiomatic-Laravel divergences: always-on validation (native
`illuminate/validation` rules) rather than opt-in; Gate/policy authorization rather than
`security:` expressions; `Rule::unique` checked pre-hydration; SQL-push-down-only relationship
windowing; polymorphic to-many on the reference provider; a `workbench/` app rather than
`examples/`; the `InteractsWithJsonApi` trait + `TestResponse` macros rather than a browser
helper; `jsonapi:*` artisan commands; and an `optimize` pipeline rather than cache warmers.

## Release passes

A release ships only when its work is complete across every repo it touches: core
(`haddowg/json-api`), the Symfony bundle, the Laravel adapter and json-api-ts. A feature,
enhancement or fix is not released while it has open questions, known bugs or follow-up tasks.
Related work found while building it, in any of those repos, belongs to the same pass. Separate
PRs keep each change small and reviewable and merge when green, but the release PRs wait until
the whole pass is polished.

- Before releasing, every caveat, edge case and deferred item from the pass is either done or
  decided. Each new rule has its consumers in step: documented parameters and the error codes
  they cause, runtime behaviour in both adapters, the TS client types and the MCP.
- Build the adapters against unreleased core locally, so Laravel parity and byte-compatibility
  hold before core releases.
- A generator contract bump ships with codegen and MCP already accepting it, released ahead of
  the documents that carry it.
- Release core, then the bundle, then Laravel, then json-api-ts, approving each release PR's
  workflow runs and reviewing its generated output.
- A released regression that blocks a consumer is fixed as a patch straight away, and it is complete
  too: related bugs or gaps found while fixing it go into the same patch pass across every repo.
- No release PR merges without the maintainer's explicit sign-off, patches and hotfixes included.
  Feature and fix PRs still merge when green; only releases wait.
- Keep an open-items ledger through the pass. Every caveat, open item or "left as is" from an
  agent's report goes on it, and each is either done, with its PR, or decided by the maintainer.
  An item is never closed by accepting it in a reply to an agent: the reply either asks for the fix
  or carries the item to the maintainer.
- Before release, point a real application that consumes the packages at the unreleased versions
  through a path repository, in a worktree and never its main checkout. Boot it, warm its cache,
  and run its static analysis and test suite. When no such application is available, use the
  example applications. A failure blocks the release.
- Every public closure or callable a user implements has a PHPStan fixture analysed with default
  settings, as a user's project would.
- A pull request that changes behaviour for an existing application says so in a sentence the
  changelog keeps.
- When the pass looks complete, bring the maintainer one summary with enough context to decide each
  item: the open-items ledger, the behaviour changes, and the consumer check's results. Nothing
  ships until the maintainer says the pass is done.

## Working with the sibling core

Core is developed as a sibling checkout at `../json-api`. When a change here needs a core
change, make it in core first (with an ADR + tests) and get it green there, then build this
package against that unreleased core locally. Both release together in one release pass. Prefer fixing core over working around it locally.
