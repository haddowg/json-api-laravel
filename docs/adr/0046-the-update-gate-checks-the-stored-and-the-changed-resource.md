# The update gate checks the stored and the changed resource

An update was authorized only against the stored resource, so a caller the policy let edit a resource could change it into one the policy would refuse them, such as handing it to another user through `relationships.owner` or `PATCH …/relationships/owner`. Every update path now checks the gate twice: against the stored resource before anything is applied, and against the result after the changes are applied and before they commit. That covers a whole-resource update (the `update` ability, plus any embedded relation's mutate ability), the relationship endpoints (the relation's mutate ability, or else `update`) and the Atomic Operations operations that use them. Checking the stored resource keeps a caller from taking someone else's resource by writing themselves in, and checking the result keeps an owner from giving it away, so a policy like "only the owner may update" means what it says on every path.

## Considered options

- Check only the result, as the Symfony bundle did until its ADR 0136. A caller could edit someone else's resource by naming themselves as its owner.
- Check only the stored resource, as this package did. An owner could hand the resource to anyone.

## Consequences

The stored-resource check on a whole-resource update now runs before document validation, so a caller it refuses gets a `403` rather than `422` errors about a resource they can't edit (this replaces the update half of ADR 0004's ordering and matches the bundle's ADR 0136). The second check runs inside the persister's transaction, after the before-update hooks and immediately before the save, and a refusal rolls everything back; a relationship mutation through a persister that can't transact is already applied when the check refuses it. A policy that should allow a transfer has to allow the new state as well. The Symfony bundle is to add the same check after the change on its relationship endpoints, which its ADR 0136 left at the parent's stored state.
