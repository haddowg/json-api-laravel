# A relationship write runs the entity-level constraints before it commits

A rule about a resource's relations, such as "an integration user needs at least one company", lives in the post-hydration entity pass, which ran only on create and update, so a write to `/relationships/companies` could break it. The relationship endpoints, and the add, update and remove operations on a relationship ref in an atomic batch, now apply the change inside the persister's transaction, run the entity pass on the changed parent in the update context, and roll back on a violation, as the Symfony bundle does. Only the entity pass runs: the request carries no attributes, and re-checking stored ones would refuse a relationship write over data the write never touched.

## Consequences

A violation of a constraint attached to a relation now points at `/data/relationships/<name>`, on create and update as well as at the relationship endpoint, so the error documents match the bundle's byte for byte; it used to point at `/data/attributes/<name>`. In an atomic batch the loop prefixes the pointer with the operation index and the whole batch rolls back. `UniqueEntity` stays a pre-hydration rule and doesn't run on a relationship write. An application whose stored data already breaks an entity-level constraint now gets `422`s on relationship writes to those resources.
