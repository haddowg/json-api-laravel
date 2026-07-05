# A hydrator can be registered for a type without a resource

- **Status:** accepted
- **Date:** 2026-07-05

**Context.** [ADR 0011](0011-standalone-serializer-capability.md) ported the read half of
the bundle's ADR 0024 ("standalone serializer/hydrator registration"): a
`#[AsJsonApiSerializer]` type with no `AbstractResource`, serialize-only by default. The
write half was left behind — a standalone serializer's allow-list could never open
`Create`/`Update` because nothing could register the type's hydrator, and a custom
action's decoupled `inputType` document had no write shape to resolve. Core has supported
the bare pair all along: `Server::registerSerializerHydrator()` accepts a serializer, a
hydrator, or both, and the `TypeMetadataResolver` seam plus the generic
`CrudOperationHandler` already tolerate a resource-less type end to end (null resource →
no validation inventory, no relations — the write pipeline is otherwise identical).

**Decision.** `#[AsJsonApiHydrator(type:, server:)]` mirrors the bundle's attribute
exactly: a class implementing core's `HydratorInterface` (optionally the same class as the
serializer — both attributes may sit on one class) is classified into a
`HydratorDescriptor` (type / server(s) — **no** operation allow-list of its own),
discovered through the same scanner channel, carried in the optimize snapshot, and
registered with core **together with the type's serializer** in one
`registerSerializerHydrator()` call (core rejects a second registration per type). The
bundle's semantics carry over unchanged:

- **Endpoints are opened only by the serializer's allow-list** (or a resource's). A
  hydrator adds write *capability*, never routes: a hydrator-only type registers its write
  shape with core (`hydratorFor()` resolves it — e.g. for a custom action's decoupled
  `inputType`) but emits no routes.
- **The write-capability guard.** A standalone type whose allow-list opens
  `Create`/`Update` with no hydrator registered for it fails **route registration** with
  the bundle's `LogicException` verbatim — the Laravel twin of the bundle's compile-time
  `validateWriteCapability()` (registration runs at boot and at `route:cache`, the
  earliest Laravel equivalent of container compilation). `Delete` hydrates nothing, so it
  needs no hydrator — only a persister, which the servability warmer holds it to at
  `jsonapi:optimize`.
- **The route registrar emits the write verbs** (`POST`/`PATCH`/`DELETE`) for a
  hydrator-paired standalone type exactly as the bundle's loader does — one route per
  allow-listed operation — while the relation/relationship-mutation routes stay
  resource-only (standalone `#[AsJsonApiRelations]` is not carried here; a resource-less
  type declares no relations).

**Consequences.** A fully resource-less writable type is now composable from independent
parts — serializer + hydrator (+ provider + persister) — matching the bundle's
mix-and-match recipes; the workbench music-catalog is untouched (its `charts`/`countries`
stay fetch-only), so byte-compat is unaffected. A bare pair still declares no field
inventory: writes through it are **not validated** by the illuminate/validation bridge and
its OpenAPI projection stays fieldless (a permissive `attributes: {type: object}`) — if
validation and a typed document matter, keep an `AbstractResource` and override one
concern via ADR 0015 instead.
