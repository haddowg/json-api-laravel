# A related type with its own provider loads its rendered members through it

`GET /vaults/1/coins` asks the `coins` provider for the members. Every other path that renders
those coins from a vault asked the `vaults` provider or read the relation off the vault: the
include batch, the lazy fallback when batching is off or impossible, linkage rendered without
`?include`, `?withCount`, the Relationship Queries window and the plain relationship endpoint.
When an application registers its own provider for `coins` to scope its rows (visibility,
tenancy), all of those showed rows the related endpoint hid, both as ids in linkage and as full
included resources. Now, when a to-many's related type is served by a provider that is neither
the parent's nor a reference provider, its members load through that provider with
`fetchRelatedCollection()`, one call per rendering parent, and render as out-of-band linkage.
That happens whether batching is on or off.

The reference providers are trusted as they are. Their related read is the relation read
itself, so an Eloquent global scope applies to the batch, the lazy load and the related endpoint
alike, and the in-memory witness reads the parent's own property.

## Consequences

- The load is per parent, not batched. A scoping provider sees the related endpoint's exact
  call, so its scope is the only thing that decides what renders.
- The members never get written onto the parent. An in-memory parent is a shared store object,
  and a written-back subset would hide rows from every later request.
- A polymorphic relation that reaches such a type has no single provider to load through, and
  core reads its linkage off the parent. Rendering its members (include, linkage, count,
  relationship endpoint) throws a `LogicException`, a 500. The polymorphic related endpoint
  throws too when a member type's provider differs from the first type's.
- A to-one has no related read in the provider interface, so its target is read off the parent
  and then checked with the target type's `fetchOne()`. When that returns nothing the relation
  renders as empty, through core's to-one linkage seam: linkage, `included`, the relationship
  endpoint, `MorphTo` and every `on()` hop on read and write (a write through a hidden hop is a
  422). The to-one related endpoint makes the same check. Here only a reference provider is
  trusted, whichever provider serves the parent, because nothing on that path calls a provider.
- The server never mattered here. Every load takes the request's server from the operation, and
  a type registered only on a named server is preloaded like any other.
