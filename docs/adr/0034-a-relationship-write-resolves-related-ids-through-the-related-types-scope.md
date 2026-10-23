# A relationship write resolves related ids through the related type's scope

ADR 0032 made every read of a relation go through the related type's own provider when that
provider scopes its rows. Writes didn't: a persister looks linkage ids up in its own storage, and
the Eloquent persister set a to-one foreign key or a join row by raw key, past any global scope on
the related model. So a write could attach, replace or remove a row the type's reads never serve.
Now each related id resolves through the same scope as the type's own endpoint before the persister
applies the write. For a type served by its own scoping provider, the handler asks that provider's
`fetchOne()`. For an Eloquent related model with global scopes, the persister finds the row through
them. An id that isn't found is a `404` whether it is hidden or absent, so a write can't be used to
learn which ids exist. A to-many replace hands the persister the hidden current members as well,
so it changes only what the client can see.

## Consequences

- The `404` replaces what an absent id did before for these types: a dangling foreign key or join
  row, or a silent skip. Reference types without global scopes keep that behaviour.
- Soft deletes are a global scope, so a trashed related id is now a `404` too.
- A write's checks run after validation and the policy gate, so a `422`, `409` or `403` still wins.
- On a create, an `on()` attribute written through a hidden target never reaches the `422` from
  ADR 0032: the target can only come from the body's linkage, which is rejected first.
- The relationship endpoint's write response loads its members through the scoped provider, like
  the relationship read. A polymorphic to-many over such a type is refused before the write.
