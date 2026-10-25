# A computed relation is scoped by the related type's provider

A relation computed with `extractUsing()` yields its rows from a closure, so we checked that it can't show rows its related type's scope hides. When the related type has its own scoping provider, every render path already loads the members through that provider's `fetchRelatedCollection()`, which receives the parent and relation, takes the closure's value as the candidate set and narrows it before any criteria, so pages stay full and every count is the narrowed count; we pinned this rather than adding a separate id probe. When the related type is a plain Eloquent model we don't probe its global scopes: the closure's own queries already run through them, and a closure that calls `withoutGlobalScopes()` or builds rows outside Eloquent has asked for exactly those rows, which a second query can't tell apart from a mistake. The Eloquent provider now counts a computed to-many for `?withCount` from the members it computes, filtered like its related endpoint, instead of reporting no count.

## Consequences

A polymorphic to-many that reaches a type with its own provider is still refused, computed or not (ADR 0032), rather than narrowed per member type. An included computed to-many under the Relationship Queries profile is windowed in PHP on the Eloquent provider, under the carve-out in ADR 0006.
