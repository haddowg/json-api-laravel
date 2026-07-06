# A persister accepts a write for async processing via `AcceptedForProcessing`

- **Status:** accepted
- **Date:** 2026-07-06

**Context.** The Symfony bundle grew an async-write seam after the original parity audit
(bundle PR #104, bundle ADR 0110): a long-running write had no spec-blessed affordance —
a persister either completed synchronously or threw. This ports the twin so the two
integrations stay in lockstep and the `vs-json-api-server` "async writes" gap closes on
the Laravel side too.

**Decision.** A `DataPersister::create()` / `update()` may return an `AcceptedForProcessing`
marker (in the `haddowg\JsonApiLaravel\DataPersister` namespace) in place of the persisted
entity to signal that it dispatched the write for **asynchronous processing** (a Laravel
queued job, a bus dispatch) rather than committing it. The `CrudOperationHandler` renders
the marker as core's `202` `AcceptedResponse` — the pollable job resource (or a meta-only
status document) with the `Content-Location` and any `Retry-After` the persister set —
instead of the `201`/`200` a synchronous write returns. The completion leg is a custom
action returning core's `303` `SeeOtherResponse` (`ActionContext::seeOther()`), so the full
JSON:API 1.1 asynchronous-processing lifecycle is expressible.

**Why the marker sits on the persister SPI, not core.** The persister is the write-side
component that already owns the storage decision, so it owns the dispatch decision too; the
only new surface is a return-value marker plus a handler branch — no new SPI method,
registry, or interface, and synchronous behaviour is untouched. *How* the work is queued
stays the application's choice (`docs/async.md` gives the queued-job recipe); the package
only owns the spec-correct `202`/`303` wire shape, which rides core's framework-neutral
`AcceptedResponse`/`SeeOtherResponse` so the bundle and this package emit byte-identical
responses.

## Consequences

An async accept cannot participate in an Atomic Operations batch — it defers the write past
the batch's all-or-nothing commit — so a marker returned while a batch is in flight is
refused (`AsyncWriteNotAllowedInAtomicOperation`, `422`, rolling the batch back). Only
`create()`/`update()` carry the seam (they return `object`); `delete()` returns `void`, so
an async delete is out of scope for now. The action handler / invoker unions and the
handler's `handle()`/`create()`/`update()` return types gained `AcceptedResponse`/
`SeeOtherResponse` so the responses type-check end to end.

Two behaviours are worth calling out because they are not divergences from the bundle but
consequences of the reference storage model:

- On a whole-resource **create** that embeds `data.relationships`, the deferred (join /
  inverse-FK) relationship applies are skipped when the persister accepts — nothing was
  keyed, so there is no parent to hang them off; the queued job owns the whole write,
  relationships included (the bundle applies embedded relationships pre-create, so it never
  had a deferred tail to skip — ADR 0009 is the reference-storage reason the tail exists at
  all).
- The async-write conformance suite does **not** assert an accepted **update** leaves its
  target unchanged on a re-read: the in-memory witness hydrates onto the stored instance by
  reference, so the in-memory read reflects the (uncommitted) hydration while an Eloquent
  re-query would not — the two providers diverge on that observation, so the suite asserts
  only the `202` contract (matching the bundle's `AsyncWriteTest`, which makes no such claim).

OpenAPI does not yet document an operation's async `202`/`303` responses — a follow-up gated
on an operation-level "may respond async" declaration, tracked identically on the bundle side.
