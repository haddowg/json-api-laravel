# Encoded-id decode is a reference-Eloquent-layer concern, resolved through a container-bound `IdEncoderResolver`

- **Status:** accepted

Core splits a resource's id-encoding contract by where the id flows (core `Id::encodeUsing()`,
bundle ADR 0038): core owns the entity's own-id transform (encode on serialize, decode a
client-generated id on create), and the integration's reference data layer owns the
id-as-lookup-key transforms — the route `{id}` a read/update/delete resolves its target by,
and the linkage ids a relationship write resolves its members by. We keep the
`DataProvider`/`DataPersister` SPI signatures **wire-id** (unchanged) and decode only inside
the reference `EloquentDataProvider` (`fetchOne`, which every show/update/delete/related
target resolution flows through) and `EloquentDataPersister` (`findRelated`/`referenceFor`/
`mutateBelongsToMany`, i.e. every linkage id before it becomes an FK or join-row key) —
mirroring the bundle's Doctrine-only decode exactly. An undecodable token can key no row, so
it is a clean `404` (read) / refused linkage target (write), never a raw wire string keyed
into an integer column. Symmetrically, the wire-keyed maps the provider hands back to the
batchers (include batches, `?withCount` counts, to-one match maps, pivot meta) encode their
keys through the same resolver so they agree with the serializer's `getId()`.

The encoder is resolved per type through a new `Server\IdEncoderResolver` — a container
singleton reading the memoized `Discovery` descriptors (type matched without instantiating,
exactly how route registration reads the Id route pattern) and constructing the one matching
resource via the container, memoized. Because the reference pair is hand-constructed in
userland (`new EloquentDataProvider($modelByType)`), the resolver is an **optional
constructor dependency resolved lazily from the container** on first use — constructor BC is
preserved, DI stays possible for tests, and outside a container every type resolves
encoder-less (wire == storage, today's behaviour).

The **in-memory witness deliberately does not decode**, mirroring the bundle's posture: its
store is keyed by whatever the app seeded, it has no encoder seam, and wire == storage there.
An encoded type is therefore exercised on the Eloquent kernel only (the bundle exercises its
`cogs`/`products` twins on the Doctrine kernel only for the same reason).
