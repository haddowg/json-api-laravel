# A resource can override its serializer or hydrator per concern

- **Status:** accepted
- **Date:** 2026-07-05

**Context.** ADR 0011 ported the standalone serializer capability but recorded the
per-resource override — the Symfony bundle's `#[AsJsonApiResource(serializer: …, hydrator: …)]`
escape hatch (bundle ADR 0023) — as a deliberate deferral, since it had no byte-compat forcing
function. It remained the one genuine parity finding (parity audit F1): a hand-written
serializer that wins reads while the resource still hydrates writes (or the mirror image) was
reachable only through field-closure/hook workarounds, and core's
`Server::register($resource, $serializer, $hydrator)` already carried the seam unused.

**Decision.** `#[AsJsonApiResource]` now carries `serializer:` / `hydrator:` class-string
parameters. The `DiscoveryScanner` validates each override implements its core contract
(a `LogicException` at scan time — the Laravel twin of the bundle compiler pass's guard;
the bundle's "registered service" half has no equivalent because Laravel's container
constructs any concrete class) and carries the class-strings on the `ResourceDescriptor`,
so they survive the `jsonapi:optimize` snapshot like every other attribute datum. The
`ServerFactory` threads them into core's `register()`, where the registry resolves the
override through the same container resolver as the resource itself — bound constructor
dependencies and `SerializerResolverAwareInterface` injection come free from core. The
overridden concern wins; the other stays field-driven, so validation, OpenAPI projection
and relations (all read off the resource's field inventory) are unchanged.

**Consequences.** The deferral note in ADR 0011 is superseded by this ADR, and parity-audit
finding F1 is resolved. A standalone `#[AsJsonApiHydrator]` (a hydrator with *no* resource)
remains unported — no bundle example exercises it and no gap forces it.
