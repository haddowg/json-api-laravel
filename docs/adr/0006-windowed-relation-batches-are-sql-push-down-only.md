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
