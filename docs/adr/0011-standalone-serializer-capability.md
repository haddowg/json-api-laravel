# A serializer can be registered for a type without a resource

- **Status:** accepted
- **Date:** 2026-07-05

**Context.** A JSON:API type's serializer is an independent capability, registerable on its
own via `#[AsJsonApiSerializer(type: …)]` with **no** `AbstractResource` — the Laravel twin
of the Symfony bundle's ADR 0024. `AbstractResource` stays the preferred sugar (it bundles a
serializer, a hydrator, relations and the field DSL from one declaration), but the decoupled,
capability-by-capability path exists for a type whose wire shape is hand-written, or that has
no resource at all (reference data, an externally-sourced collection). PLAN decision 3 already
lists serializers among the discoverable capability types, so this is in-scope completion, not
new scope; the **forcing function** was the byte-compatibility check (PLAN decision 11), which
proved the package's music-catalog document was missing the bundle example's `charts` +
`countries` types — a resource-less `AbstractResource` cannot substitute, because its OpenAPI
projection carries a `{Type}Attributes` `$ref` where a fieldless type must carry an inline
`attributes: {type: object}`.

**Decision.** A class implementing core's `SerializerInterface` and carrying
`#[AsJsonApiSerializer]` (and not an `AbstractResource`) is classified by the
`DiscoveryScanner` into a `SerializerDescriptor` (type / uriType=type / server(s) /
operation allow-list / OpenAPI tags), lazily container-constructed like a resource. The
`ServerFactory` registers the type through core's `Server::registerSerializerHydrator()`
(after the resources, so a resource for the same type always wins), so it serves reads through
the existing pipeline on **both** providers — the serializer renders the wire shape while a
data provider (registered independently) supplies the objects. The `RouteRegistrar` emits
operation-gated routes for it (no relation or write routes — a resource-less type declares
neither), and the OpenAPI `MetadataSource` projects it fieldless (`hasFields: false`, empty
fields/filters/sorts/relations), which core's projector renders as the inline attributes
object byte-identically to the bundle. The workbench declares `charts` (a fixed in-memory
provider) and `countries` (sourced from `symfony/intl`) exactly as the bundle example does, on
both provider arms; `composer byte-compat` now diffs empty on every server.

**Consequences.**

- **Serialize-only by default.** A standalone serializer's operation allow-list defaults to
  **empty** (no endpoints) — the deliberate asymmetry against an `AbstractResource`, which
  defaults to all five. `charts`/`countries` open exactly the two fetch verbs.
- **The URI segment is the type.** The descriptor's `uriType` is the JSON:API type; the
  serializer's own `UriTypeAwareInterface::uriType()` is a runtime link concern that must agree
  with it (both are `charts`/`countries` here), matching the bundle's descriptor rule.
- **Serializer OVERRIDE on a resource is deferred (explicit non-goal for this port).**
  *(Superseded by [ADR 0015](0015-per-resource-serializer-hydrator-override.md), 2026-07-05:
  the override is now carried on the attribute.)* The
  bundle's `#[AsJsonApiResource(serializer: …)]` escape hatch (its ADR 0023 — a hand-written
  serializer that wins serialization while the resource still hydrates writes, the bundle's
  `TrackSerializer`) is **not** ported here. It is not a cheap add: it needs an override map
  threaded per-server into `Server::register($resource, $serializerOverride, …)`, discovery to
  carry the override class off the attribute, container binding of the override's constructor
  dependencies, and the `SerializerResolverAwareInterface` injection the bundle's `TrackSerializer`
  relies on to render relations. The standalone capability (this ADR) is the one the byte-compat
  gap forced and is self-contained; the per-resource override is a separate design with its own
  wiring surface and no byte-compat forcing function (the music-catalog document is identical
  with or without it, since `TrackResource` and `TrackSerializer` project the same `tracks`
  shape). It is recorded here as a deliberate deferral, to be picked up if a concrete need
  arises.
