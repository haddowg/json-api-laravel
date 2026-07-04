# This package is the second pre-1.0 integration witness for the core

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** `haddowg/json-api` is a framework- and storage-agnostic JSON:API 1.1
library that has not yet frozen its public API — its 1.0 release PR (`json-api#6`)
is open and deliberately held. The Symfony bundle (`haddowg/json-api-symfony`) has
been the *first* integration witness: building it proved the core's lifecycle,
serialization, hydration, and Provider/Persister seams against a real framework
before the API freezes. A second, independent framework integration is the stronger
test — a seam that reads clean from one framework's idioms can still be wrong for
another's.

**Decision.** This package is the **second pre-1.0 integration witness**. Core 1.0
stays held until this package proves **reads *and* writes end-to-end** on the
Eloquent reference layer. Friction found here is **fixed in core, not worked around**
locally: a core change lands as a PR on the core repo (with its own ADR + tests),
merged to core `main`, *before* this package consumes it — exactly the contract the
bundle already honours. The dependency stays `haddowg/json-api: dev-main` for the
whole of pre-1.0 (resolved via a global Composer path repository locally, a global
VCS repository in CI), with **no per-phase tag pin**. The single pin to `^1.0`
happens once, at the end (Phase 5), when core 1.0 ships to Packagist and this file
is deleted.

**Consequences.** The package is coupled to a moving `dev-main` target through
pre-1.0, so a core change can break a build until the sibling is pulled — accepted,
because keeping core cheap to change is the whole point of the witness. Every phase
closes on dual-provider conformance (in-memory vs Eloquent) so a finding stays
attributable to either a core seam or the data mapping. A hard obligation follows
from being the *second* witness: the OpenAPI document this package projects must be
**byte-compatible** with the bundle's (both implement core's `Metadata/*` contract),
proven by a CI diff — the surest evidence the core contract, not the framework, owns
the shape of the output.
