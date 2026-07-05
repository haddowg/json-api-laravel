# Null-in-comparison semantics are a known witness divergence, resolved in core

- **Status:** resolved (core ADR 0116 landed)
- **Date:** 2026-07-04
- **Resolved:** 2026-07-05

**Context.** The dual-provider conformance suite (PLAN decision 9) treats core's
in-memory `ArrayFilterHandler` as the witness the Eloquent reference provider must
match byte-for-byte. They disagree on ONE case: an ordered comparison (`<`, `<=`,
`>`, `>=`, and therefore a `Range` bound) against a column whose value is `null`.
Core's in-memory handler evaluates the comparison in PHP after a coercion (e.g.
`null` numeric-coerces toward `0`, so `null <= 9.0` is *true* and `null >= -1` is
*true*), which **includes** the null row; SQL three-valued logic makes the same
predicate `UNKNOWN`, which **excludes** it. So `GET /albums?filter[rating][max]=9.2`
would return different result sets per provider — the divergence is real, not an
Eloquent execution bug.

**Decision.** This is a **core reference-semantics question**, not something to work
around locally (PLAN decision 1, the witness contract): the fix — most likely
"an ordered comparison against `null` never matches", aligning the in-memory witness
with SQL — belongs in `haddowg/json-api` as a core change + ADR, merged to core
`main` before this package asserts the case. Until then, the workbench **sidesteps
the whole class**, exactly as the Symfony bundle does: ordered comparison and
`Range`/`DateRange` filters are declared **only over columns with no null rows**
(artists `track_count`, albums `released_at`), and null presence is refereed with
the explicit `WhereNull`/`WhereNotNull` filters instead. A conformance test recorded
the divergence as a visible, skipped marker pointing here (until the resolution below
replaced it with a real assertion).

**Consequences.** No comparison/range filter in the workbench vocabulary sits on a
null-bearing column, so the shipped surface cannot expose the divergence over HTTP.
When core defines the semantics, the marker is un-skipped and a null-bearing range
can be re-introduced. Tracked for the Phase 5 parity audit.

**Resolution (2026-07-05).** Core landed
[ADR 0116 — *An ordered comparison against `null` never matches*](https://github.com/haddowg/json-api/blob/main/docs/adr/0116-ordered-comparison-against-null-never-matches.md):
the reference in-memory `ArrayFilterHandler` now excludes a `null` operand from an
ordered `<`/`<=`/`>`/`>=` — and therefore a `Range`/`DateRange` bound — mirroring SQL
three-valued logic instead of coercing `null` toward `0`; loose/strict equality keeps
native PHP semantics and null *ordering in sorts* is untouched (a separate concern,
still tracked by the ADR 0006 sort family). With the witness converged, a null-bearing
`Range` was re-introduced to the workbench (`AlbumResource` `filter[rating]` over the
nullable `average_rating`, matching the bundle's `filter[rating]` for OpenAPI
byte-compat), and the divergence marker was replaced with a REAL dual-provider
assertion that both providers exclude the null rows identically
(`ReadConformanceTestCase::orderedComparisonAndRangeOverANullableColumnExcludeNullsIdentically`;
the in-memory witness's converged behaviour is also pinned in
`InMemoryDataProviderFilterTest::itMatchesGreaterThanOrEqualAndLessThanOrEqualComparisons`).
This ADR is closed.
