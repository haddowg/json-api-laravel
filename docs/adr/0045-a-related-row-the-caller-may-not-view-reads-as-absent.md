# A related row the caller may not view reads as absent

A type restricted only by its read policy could still be read through another type, as an `included` resource or a related endpoint's data, because only the parent's read was checked. Each related row a response renders now has to pass its own type's `view` gate, the check `GET /{type}/{id}` applies to that row, and a refused row reads as absent: it is left out of `included` and of the relationship's `data` (a to-one renders `null`), and out of the related and relationship endpoints. We chose absence over a `403` or a links-only relationship because one refused row shouldn't fail a whole document or hide its visible siblings, and it matches how a row hidden by a scoping provider already disappears.

## Considered options

- Refuse the whole include path with a `403`. One row the caller can't see would fail an otherwise valid request, and the error would show that the row exists.
- Withhold the relationship's data, as a relation read ability does. That hides the visible rows along with the refused one.
- Check `viewAny` on the related type. That is a class-level gate, so it can't express per-row ownership, which is what `view` policies usually encode.

## Consequences

The rows are filtered in the to-many and to-one linkage seams the handler already gives core, so the check runs on rows already loaded, one policy call per row and type per operation, with no extra queries. Core reads a polymorphic to-many off the model without that seam, so the relation authorizer withholds its data as a whole when it holds a refused row, and does the same for a lazy to-many that wasn't preloaded but is about to be included. A flattened `on()` attribute read through a refused row is `null`, and a write through it is a `422`, as with a scoped row. Collections keep their `viewAny` check and query scope, and a filtered page can come back shorter than its size with its total unchanged. Apps that relied on `?include` to expose rows their `view` policy refuses will see those rows disappear. The Symfony bundle is to apply the related type's read expression to each row the same way.
