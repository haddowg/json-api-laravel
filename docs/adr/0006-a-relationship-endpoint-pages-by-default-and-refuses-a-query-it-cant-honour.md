# A relationship endpoint pages by default and refuses a query it can't honour

A client whose `?sort`, `?filter` or `?page` is silently dropped believes it applied, which is worse than an error. A monomorphic to-many relationship endpoint, pivot or not, serves page 1 of its paginator even with no query string and honours sort, filter and page (pivot columns included), so it matches what the OpenAPI document advertises. A shape that can't window, a to-one or a polymorphic to-many (any morph relation, or one listing several types), answers such a query with a `400` and renders its whole linkage only when unqueried.

## Consequences

- A relation declared `withoutPagination()` serves its whole linkage.
- Lifting a refusal later is additive: a `400` becomes a `200`.
