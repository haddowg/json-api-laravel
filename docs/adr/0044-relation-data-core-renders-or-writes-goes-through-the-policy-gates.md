# Related data passes the related type's gates unless the relation declares its own read permission

A type restricted by its read policy could still be read through another type as `included` data or a related endpoint. Rows another type renders pass their own type's gates, split by shape: a single related row passes `view` and reads as absent when refused, while a to-many passes `viewAny` once and shows exactly what the related type's query and scopes return, withheld to its links when refused. A relation can replace those gates for itself with `security(read:)` or a `view{Relation}` policy method checked against the parent; scoping always applies, and a write through `on()` needs the related type's `update` on the row it lands on.

## Considered options

- **Check `view` on every row of a list and drop refused ones.** Pages came out short and totals counted rows the caller couldn't see.
- **Refuse the whole include with a `403`.** One row would fail a valid document and reveal that it exists.
- **Check only `viewAny` for single rows too.** It can't express per-row ownership.

## Consequences

Rows that must never be listed need a query scope; `view` no longer hides them from lists. An app with an existing `view{Relation}` policy method finds it starts deciding that relation.
