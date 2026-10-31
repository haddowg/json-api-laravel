# Write authorization checks the stored resource first and the changed resource before commit

A gate checked only against the stored resource lets an owner hand it to someone else, and one checked only against the result lets a caller take someone else's resource by naming themselves its owner. So an update (whole-resource, relationship endpoint or atomic) is checked against the stored resource before read-only refusal, validation or hydration, and again against the changed resource inside the write transaction just before it saves; a refusal rolls the change back. A create has no stored state, so it refuses read-only attributes, validates the document, then checks the `create` gate before hydration, keeping the bundle's `422`-before-`403` order there.

## Consequences

- Document validation on a create runs before the gate, so a caller the policy would refuse can still probe a uniqueness `422`. An app that must close that oracle has to authorize ahead of validation itself.
- A relationship change through a persister that is not a `TransactionalDataPersisterInterface` is already applied when the second check refuses it.
