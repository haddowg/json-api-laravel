# Write authorization is timed on the pristine subject, before hydration

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** The Symfony bundle authorizes a write AFTER hydrate + `validateEntity` — the
policy sees the *mutated* entity, and an entity-level `422` precedes the `403`. This
package deliberately diverges (the option the Phase-2 blueprint §7.3 recommended and
flagged for confirmation): `CrudOperationHandler::create()`/`update()` authorize the
**pristine** subject **before** hydration mutates it — on update the stored, loaded model
(ownership decided on stored state, never attacker-influenced attributes); on create the
blank instance's class token (`create($user)`). Document-semantic validation still runs
**first**, so the ordering is: document `422` → policy `403` → hydrate → entity-seam `422`
→ persist. The `422`-before-`403` half is bundle parity; the pristine-subject /
authorize-before-hydrate half is the divergence.

**Consequences (reviewable trade-offs).**

- **Entity-seam `422` follows the `403` on create/update.** A custom
  `EntityConstraintInterface` check (post-hydration — see ADR references in
  `ResourceValidator::validateEntity()`) runs *after* the gate, so an unauthorized write
  gets a `403` and never surfaces the entity-level `422`. This is strictly more secure
  (an unauthorized principal learns less), but it is a visible ordering difference from
  the bundle.
- **Validate-before-authorize is a uniqueness/cross-field oracle.** Because document
  validation runs before the gate, a principal the update/create policy would *deny* can
  still probe a `422` that reflects stored state: the pre-hydration `Rule::unique` DB
  query (PLAN decision 6) and the merged cross-field pass answer *before* the `403`. So
  `PATCH /articles/1 {slug:'x'}` returns a UNIQUE `422` iff some row already holds `slug`
  `'x'`, disclosed to a caller the policy rejects. This is bundle parity (the bundle's own
  post-hydration `UniqueEntity` has the same property), graded a minor oracle; the
  `Rule::unique` divergence makes it a direct DB query on attacker-controlled values. It
  is recorded here as a deliberate decision rather than an accident. An application that
  must close the oracle can move the write authorization ahead of document validation
  (accepting the `403`-before-`422` reordering) — a candidate for the Phase-4
  authorization-hardening pass.
