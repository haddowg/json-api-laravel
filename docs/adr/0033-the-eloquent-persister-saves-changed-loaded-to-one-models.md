# The Eloquent persister saves changed, loaded to-one models after the parent

A write through an `on()` attribute changes a related model that the parent holds as a loaded
relation. Eloquent's `save()` writes only the parent's row, so the change was lost. Doctrine's
flush saves every changed managed entity, and the in-memory witness shares objects. Now
`create()` and `update()` walk the parent's loaded to-one relations, hop by hop, and save each
model that exists and is dirty, inside the parent's transaction.

## Considered options

- `push()` saves every loaded relation, to-many collections and clean models included, and
  inserts related models that don't exist yet. That is more than a flattened write changes.
- Walking only the resource's `on()` paths would put the resource and its server inside a
  persister that sees only a type and a model, and it would still need the dirty check. The
  relation walk reaches the same models, through the relations core read them from.

## Consequences

- A loaded to-one model changed some other way, for example by a before-save hook, is saved
  too. Doctrine's flush does the same.
- The related save goes through the related model's own connection. A model on another
  connection commits outside the parent's transaction.
