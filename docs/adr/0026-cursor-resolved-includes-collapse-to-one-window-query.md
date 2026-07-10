# Cursor-resolved includes collapse to one window query per relation

- **Status:** accepted — supersedes the per-parent mechanism of [ADR 0024](0024-client-selectable-pagination-carries-a-page-schema-and-resolves-up-front.md)

ADR 0024 lifted the throw on a cursor-resolved **included** relation by minting a first
cursor page **per parent** — looping the parent-scoped `fetchRelatedCollection` keyset
fetch once for each parent on the page (N keyset `LIMIT` queries per relation). It
deferred the single-query collapse the offset include already has (the round-2 #21
follow-up) because of an *unverified* fear: that the keyset's portable NULL=largest
`ORDER BY` term — `CASE WHEN <col> IS NULL THEN 1 ELSE 0 END <dir>` — might not compose
correctly, or might carry bindings that the order→select binding migration could disturb,
once folded **inside** a `row_number() OVER (… ORDER BY …)` window.

That blocker is now **disproven by construction**. It is the *same* `orderByRaw` term the
offset include window has always emitted, and Laravel's grammar splices raw orders
verbatim into the `OVER` clause (`compileOrdersToArray`), carrying **no** bindings — so
the order→select migration cannot touch it. So `EloquentDataProvider::fetchWindowedBatch`
now routes a boundaryless `CursorWindow` (an include carries no cursor token, so there is
no keyset `WHERE`, only the `ORDER BY` + the count-free `limit + 1` `hasMore` probe) to a
**single** `Builder::groupLimit` query — `ROW_NUMBER() OVER (PARTITION BY <parent FK>
ORDER BY <keyset>)` capped at `limit + 1` per partition — exactly mirroring the offset
include path. The **N→1** collapse: a collection include of one cursor relation over M
parents is one statement, not M. The only difference from the offset window is the order —
the resolved `KeysetColumn` list from the shared `KeysetResolver` (the active sort + the
deduped PK tiebreak, whose direction rides the **last active directive**, never a
hardcoded `id ASC`) via `EloquentKeyset::orderBy` — and the mint: each partition's slice
is minted through the *same* `CursorTokenMinter` and the *same* row→value reader the
per-parent path uses, so the tokens are byte-identical.

The per-parent loop (`fetchCursorBatchPerParent`) is **retained** as the fallback for a
relation that cannot push down as one partitioned window (polymorphic, no Eloquent method,
or a bounded — non-first — page), and remains the related-collection **endpoint**'s path
(`GET /{type}/{id}/{rel}` under a cursor paginator, which owns the keyset `WHERE` via
`EloquentKeyset::applyAfter`). The in-memory witness keeps windowing each parent per-parent
and is the **parity referee**: `CursorIncludeConformanceTestCase` asserts the SQL push-down
and the PHP witness render byte-identical pages (including a collection include sorted on a
**nullable** column with the null bucket interleaved across parents and mixed surplus — the
exact NULL-inside-`row_number()` composition #21 feared), while `EloquentWindowedCursorRelationBatchTest`
pins the single-statement window SQL for both a `hasMany` and a `belongsToMany` (pivot)
include. A Doctrine twin of this collapse follows in the Symfony bundle (the cursor
include single-window ADR there).
