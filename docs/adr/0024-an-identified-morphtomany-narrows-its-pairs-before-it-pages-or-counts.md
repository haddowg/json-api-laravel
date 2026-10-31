# An identified MorphToMany narrows its pairs by key before it pages, counts or renders

A `MorphToMany` declared with `identifiedBy()` admits each scoped member type once, with a key-only query over all of a parent's stored pairs, before it cuts a page or takes a count, and only then loads the rows the page shows, so a hidden member never makes a page short or a total wrong and a large membership never loads in full. A type counts as scoped only when a global scope or a provider of its own may hide a row, since a `view` check never filters a list (ADR 0018).

## Considered options

- Loading every member's row to narrow them: correct, but a parent with thousands of members loads them all to show one page.
- Treating a `view` check as a guard, as for an identified `MorphTo` (ADR 0021): it would hide rows from a list that `viewAny` already admitted.
