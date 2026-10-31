# The Eloquent persister saves changed, loaded to-one models after the parent

A write through an `on()` attribute changes a related model the parent holds as a loaded relation, and Eloquent's `save()` writes only the parent's row, where Doctrine's flush saves every changed entity. So `create()` and `update()` walk the parent's loaded to-one relations, hop by hop, and save each existing dirty model inside the parent's transaction.

## Considered options

- **`push()`** saves every loaded relation, to-many collections and new models included, which is more than a flattened write changes.
- **Walking only the resource's `on()` paths** would put the resource and server inside a persister that sees only a type and a model.

## Consequences

A loaded to-one changed some other way, such as by a hook, is saved too, and a model on another connection commits outside the parent's transaction.
