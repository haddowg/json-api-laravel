# Windowed relation batches are SQL push-down only — no toggle, no PHP fallback

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** The Doctrine reference provider in the Symfony bundle ships a
`doctrine.window_functions` toggle with a PHP-windowing fallback for per-parent relationship
paging (the Relationship Queries profile). PLAN decision 9 (Gregory's explicit call, reversing
the earlier ship-later recommendation) is that the Eloquent reference provider does **not**
carry that toggle or fallback: a windowed multi-parent relation batch is a SQL push-down ONLY
— `groupLimit`/`ROW_NUMBER() OVER (PARTITION BY …)` with the relation's order plus a
deterministic id tie-breaker, `hasMore` via an N+1 probe on the same query, totals via a
grouped `COUNT`. Every first-party Laravel database driver has window functions, so the
Doctrine toggle's portability rationale does not transfer. This is the recorded divergence from
the Doctrine reference.

**Consequences.**

- **Reaching the windowed-batch seam in PHP is a programming error, not a fallback.**
  `EloquentDataProvider::fetchRelatedCollectionBatch()` throws a `LogicException` for any
  non-null window rather than windowing in PHP; the Phase-3a include fast path only ever calls
  it with a null window. The SQL push-down itself is built in Phase 3b (its sole consumer).
- **The in-memory witness is the determinism referee.** The witness runs core's
  `WindowExecutor` and appends the SAME final `id ASC` tiebreak
  (`InMemoryDataProvider::withPkTiebreak()`) the SQL `ORDER BY` will, so the conformance suite
  referees SQL vs PHP windowing on every run. The referee is exercised now (a windowed in-memory
  batch over tied sort keys asserts id-ascending resolution) even though its Eloquent consumer
  is Phase 3b, so a tiebreak regression surfaces before 3b builds against it.
- **Tie-break determinism is pinned, not incidental.** A relation page ordered only on a
  non-unique column would otherwise diverge between the SQL `ROW_NUMBER` order and PHP's stable
  sort; the appended primary-key tiebreak makes the two provably identical.

## Addendum (Phase 3b — the push-down is built)

`EloquentDataProvider::fetchWindowedBatch()` now replaces the Phase-3a `LogicException`. It
applies the window through Eloquent's own relation `limit()`: a multi-parent eager relation's
parent does not exist, so `HasOneOrMany::limit()` / `BelongsToMany::limit()` /
`HasManyThrough::limit()` route to `Builder::groupLimit($value, $existenceCompareKey)`. The base
`Grammar::compileGroupLimit()` wraps the whole (joined) query in a derived table
—`select * from (<inner>, row_number() over (partition by <compareKey> <orders>) as laravel_row) where laravel_row <= N [and laravel_row > offset] order by laravel_row`—
whose outer `select *` re-exposes the foreign key `match()` partitions on and the aliased
`pivot_*` columns `belongsToMany` hydration reads, so the ORM eager pipeline works unchanged
through the wrap. Filters + the sort push down via the shared `CriteriaApplier`; the primary-key
`ORDER BY … asc` tiebreak is appended after the relation order.

Two watch items from PLAN are resolved:

- **Laravel 12-vs-13 `groupLimit` grammar — identical.** The window-function `groupLimit`
  feature landed in Laravel 11; `Query\Grammars\Grammar::compileGroupLimit()` /
  `compileRowNumber()` and the `limit()`→`groupLimit` routing on `HasOneOrMany` (13.x line 557),
  `BelongsToMany` (1506) and `HasOneOrManyThrough` (774) are **byte-identical across Laravel 12
  and 13** — only the *driver* grammars carry server-version legacy overrides
  (`MySqlGrammar::useLegacyGroupLimit()` emulates the window with user-variables on MySQL
  < 8.0.11 and never on MariaDB; `SQLiteGrammar` uses the base ROW_NUMBER path on SQLite ≥ 3.25).
  Every first-party driver at its supported floor has the window-function path, so decision 9's
  "no toggle, no PHP fallback" holds; the CI matrix (Laravel 12/13 × lowest/highest) is the
  backstop. The base grammar the push-down depends on is unchanged 12↔13.
- **NULL ordering — the plain `ORDER BY` already matches the witness (no CASE term).** The
  determinism referee is the in-memory witness, whose windowed batch orders through core's
  `ArraySortHandler` — a raw PHP `<=>` comparator, so NULL sorts *first* on ASC and *last* on
  DESC (`null <=> value === -1` for any non-zero value). SQLite (and MySQL) place NULLs first on
  ASC / last on DESC by default, so the shared applier's plain `orderBy(col, dir)` — the SAME
  code the related-collection endpoint already windows through — is byte-identical to the witness
  on SQLite; **no `CASE WHEN col IS NULL` term is emitted** (the earlier design note prescribed a
  null-*largest* CASE, which would have *diverged* from this witness — the witness is
  null-*smallest*). The one residual divergence is inherent and shared with the ADR 0003 family:
  PHP treats `null <=> 0` as equal while SQL orders NULL strictly before `0`, so a sort column
  mixing NULL and `0` can page differently — the conformance suite seeds non-NULL tied keys for
  the tiebreak referee and non-zero values for the NULL-ordering assertion to stay on the
  agreed-common ground.

  **Driver caveat (recorded, not resolved).** The "no CASE term" conclusion is scoped to
  SQLite and MySQL, whose default NULL placement (NULLs first on ASC / last on DESC) matches
  the witness's null-smallest `<=>`. PostgreSQL and SQL Server — both first-party Laravel
  drivers decision 9 leans on — default to NULLS **LAST** on ASC / FIRST on DESC, the inverse:
  on those drivers a windowed page (and every plain sorted read, since it is the SAME shared
  `orderBy` applier — ADR-0003 family) over a nullable sort column pages NULL members onto the
  opposite end from the in-memory witness, an unrefereed divergence CI (SQLite-only) never
  sees. This is a documented open watch item shared with the package's whole sort surface, not
  a windowed-batch-specific defect; the driver-gated fix (emit a leading
  `CASE WHEN col IS NULL THEN 0 ELSE 1 END` term for nullable windowed sorts, mirroring
  `EloquentKeyset`'s cursor discipline — or the grammar's `NULLS FIRST/LAST` where supported)
  rides the sort-surface work, not this ADR.
