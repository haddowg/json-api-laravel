# The SPI carries wire ids, and the reference Eloquent layer decodes them

Core encodes and decodes a resource's own id, but an id used as a lookup key (the route `{id}`, a linkage id) has to be decoded somewhere before it reaches storage. The provider and persister interfaces keep wire ids, and only the reference Eloquent pair decodes them through the type's id encoder, as the bundle's Doctrine pair does, so a custom provider sees the ids the client sent. An undecodable id keys no row, so it is a `404` or a refused linkage target.

## Consequences

- The in-memory witness does not decode, so encoded types are exercised on the Eloquent provider only.
- A binary UUID key stays raw bytes on the model, because Eloquent binds whatever `getAttribute()` returns and an `AsBinary::uuid()` cast would miss every row; `BinaryUuidEncoder` declared on the resource's `Id` gives the canonical UUID on the wire.
