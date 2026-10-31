# Windowed relation batches are SQL push-down only, with no toggle and no PHP fallback

The bundle's Doctrine provider carries a window-function toggle with a PHP fallback for per-parent relation paging. Every first-party Laravel driver has window functions, so the Eloquent provider windows a multi-parent relation batch only in SQL: one `groupLimit` (`ROW_NUMBER() OVER (PARTITION BY …)`) query with the relation's order and a primary-key tiebreak, `hasMore` from a `limit + 1` probe and totals from a grouped `COUNT`. A cursor-paginated include is the same single partitioned query ordered by the keyset, since an include always starts at the first page.

## Consequences

- A computed relation (`extractUsing()`) has no query to push down, so its window runs in PHP over the members it computes. A cursor relation that can't be one partitioned query (polymorphic, no relation method, a non-first page) falls back to one keyset query per parent.
- The in-memory witness appends the same primary-key tiebreak, so the conformance suite referees SQL against PHP windowing on every run.
- NULL placement in an offset window follows the driver's default, which matches the witness on SQLite and MySQL but not on PostgreSQL or SQL Server; CI runs SQLite only.
