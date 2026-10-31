# An identified MorphToMany narrows its pairs by key before it pages, counts or renders

A `MorphToMany` declared with `identifiedBy()` admits each scoped member type once, with a key-only query over all of a parent's stored pairs, before it cuts a page or takes a count, and only then loads the rows the page shows, so a hidden member never makes a page short or a total wrong and a large membership never loads in full. A type counts as scoped only when a global scope or a provider of its own may hide a row, since a `view` check never filters a list (ADR 0018).

## Considered options

- Loading every member's row to narrow them: correct, but a parent with thousands of members loads them all to show one page.
- Treating a `view` check as a guard, as for an identified `MorphTo` (ADR 0021): it would hide rows from a list that `viewAny` already admitted.

## Amendment: the relationship endpoint pages the pairs

The relationship endpoint of an identified `MorphToMany` pages its admitted pairs the way the related endpoint does, with the same paginator, totals and `page` parameters, and renders them without loading a row, an exception to ADR 0006 for this one shape. The pairs give it a stable order and an exact total that other polymorphic to-many relations lack, so those keep answering a query with a `400`.
