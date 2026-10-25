# Related rows pass their own type's read gates

A type restricted only by its read policy could still be read through another type, as an `included` resource or a related endpoint's data, because only the parent's read was checked. Rows another type renders now pass their own type's gates, split by shape. A single related row (to-one linkage and its `included` resource, the to-one related and relationship endpoints, and `on()` reads and writes through a to-one) passes its type's `view` gate, the check `GET /{type}/{id}` makes, and a refused row reads as absent. A to-many is a list: its linkage, its members in `included`, its related and relationship endpoint pages, `?withCount` and the Relationship Queries window show exactly what the related type's query returns, with its global scopes or scoping provider, and the relation passes the related type's collection gate, the `viewAny` check `GET /{type}` makes, once per type per operation; when that refuses the caller the relationship renders only its links, with no data, meta or included members, and its endpoints answer as `GET /{type}` does. A polymorphic to-many needs the collection gate of every member type.

## Considered options

- Check `view` on every row of a list and drop the refused ones. This shipped first, but it cut pages short and left totals counting rows the caller couldn't see, so the rows, the page size and the counts disagreed. Row visibility on a list now comes only from the query, as it does for `GET /{type}`.
- Refuse a whole include path with a `403` when a row is refused. One row would fail an otherwise valid document and show that the row exists.
- Check only `viewAny` for single rows too. That can't express per-row ownership, which is what `view` policies usually encode, and a single row has no page or count to disagree with.

## Consequences

Every count, from `meta.page.total` and the window total to `?withCount`, comes from the same query, scopes and scoping provider as the rows, so it equals the rows a client can page through, and a withheld relation carries no count. Rows that must never be listed have to be filtered by a query scope; `view` no longer hides them from lists. A flattened `on()` attribute read through a refused to-one row is `null`, and a write through it is a `422`, as with a scoped row. The Symfony bundle applies the same split, with its `securityList` expression as the collection gate.
