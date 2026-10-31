# A related type's scope governs every read and write that reaches its rows

When an application registers its own provider for a type to scope its rows (visibility, tenancy), every path that reaches those rows from another type goes through that scope, not only the type's own endpoints. A to-many's members load through the related provider's `fetchRelatedCollection()` per parent; a computed relation's closure only supplies the candidates that provider narrows; a polymorphic to-many is narrowed per member type in one lookup per type (`fetchMany()` where implemented); a to-one target is kept only while the related `fetchOne()` returns it; and each related id in a relationship write resolves through the same scope, an unseen id being a `404` like an absent one. The reference providers are trusted as they are, since their related read is the relation read and an Eloquent global scope already applies to it.

## Consequences

- Narrowing runs before any page is cut or count taken, so linkage, `included`, both endpoints and `?withCount` agree.
- A to-many replace leaves the hidden current members attached.
- A computed closure that calls `withoutGlobalScopes()` on an Eloquent type is not second-guessed.
