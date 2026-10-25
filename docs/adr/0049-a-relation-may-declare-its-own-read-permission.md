# A relation may declare its own read permission

Since ADR 0045 a relation's data passes the related type's own gates, `viewAny` for a to-many and `view` for each to-one row, which left no way to expose one relation of a type the caller can't list, or to block one relation of a type they can. We split the question in two. Permission is whether this caller may see a relation's data at all. Scoping, from global scopes and scoping providers, is which rows exist; it always applies and every count follows it. A relation can now declare its permission, and a declared permission replaces the related type's gates for that relation only. When it allows, the data renders under scoping alone everywhere it appears, including `?withCount`, the window and `on()` through a to-one. When it refuses, the relation is withheld to its links, its endpoints answer `403`, and `on()` through a refused to-one reads `null` and writes `422`. Writes otherwise stay as they were.

## Precedence

1. An explicit `security(read: 'ability')`, checked against the parent, as before.
2. The convention `view{StudlyRelation}`, for example `PostPolicy::viewTags($user, $post)`, checked against the parent through the type's dedicated `policy:`, the model's Gate policy or a Gate ability. It counts as declared only when that method or ability exists, because the Gate denies an undefined ability and a missing method must not read as a refusal. A name that collides with a standard ability (`viewAny`, `view` and the write abilities) is never a convention.
3. A bool `security(read: true|false)` declares the relation and enforces nothing in the app, so it skips the related type's gates the way it already skipped the parent's.

With none of these, ADR 0045 stands.

## Consequences

At the relation's endpoints the convention ability takes the place of the parent's `view`, exactly as an explicit string ability always has, so an app that happens to have a `view{Relation}` policy method sees it start to decide that relation. The answer is memoized per parent and relation for each operation. The Symfony bundle mirrors this with its relation read expression and its own convention.
