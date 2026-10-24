# A pivot relationship endpoint pages like the related endpoint and reads `meta.pivot` off the page

- **Status:** accepted

`GET /{type}/{id}/relationships/{rel}` on a pivot `belongsToMany` now windows exactly as a non-pivot
to-many does (ADR 0038): it pages by default and honours `?sort`/`?filter`/`?page`, including the
pivot field sorts and the relation's `pivot.`-columned filters, closing the pivot tail ADR 0010
deferred. The OpenAPI document already advertised those parameters, and the Symfony bundle
already served them. Rather than port the bundle's separate pivot query and result types, the
Eloquent provider keeps one `fetchRelatedCollection()` path and, for a pivot relation, selects
the join columns under Eloquent's own `pivot_` aliases and hydrates the pivot model onto each
member in an after-query callback. `meta.pivot` is then read off the fetched page through the
new `QueriesPivotColumnsInterface::pivotMapOf()`, so the page and its pivot meta cost one query
and the related endpoint loses its separate whole-association pivot read. A provider that does
not implement the interface (the in-memory witness, as with the bundle's non-Doctrine providers)
never receives the pivot vocabulary, so a pivot sort or filter key is a `400` there. A cursor
window keeps the pivot filters but not the pivot sorts, because the keyset reads its columns off
the related model. `?withCount=_self_` behaves as on any windowed to-many, and the relationship
document carries no `meta.total`, as in the bundle.
