# Embedded relationship writes on a create split by which side holds the foreign key

A whole-resource write strips `data.relationships` before core hydrates and applies each one through the persister's `mutateRelationship()`, as the bundle does, but the bundle applies them all before the create and a join or inverse-FK row needs the parent's key. So on a create only an owner-side foreign key (`BelongsTo`, `MorphTo`) applies before the insert; `HasOne`, `HasMany`, `BelongsToMany` and `MorphToMany` apply after it, and the create and its deferred writes share one transaction so a failing join insert rolls the parent back. An update applies every embedded relationship before the save.

## Consequences

- The split is by storage side, not cardinality: a `HasOne` placed before the insert would save its row against a null parent key.
- The `cannot*` prohibitions are skipped on a create, since a create sets initial state.
