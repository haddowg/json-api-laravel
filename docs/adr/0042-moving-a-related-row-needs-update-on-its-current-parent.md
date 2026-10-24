---
status: accepted; the rule for a row with no parent is superseded by ADR 0047
---

# Moving a related row needs update on its current parent

On a relation where a related row has one parent (an inverse foreign key: `HasMany`, `HasOne` and their morph variants), adding a row to a parent also takes it from the parent that holds it now, so checking only the URL parent's `update` let a client take rows from parents it couldn't touch. An add or replace that would move a row from another parent now needs the `update` ability on that current parent, or failing that on the row itself, otherwise it gets a `403`; a row with no parent moves freely, and a remove only clears rows the parent actually holds, so removing another parent's row is a no-op. Asking about the current parent keeps the default open (with no policy every check passes) while letting a policy guard ownership on either side, and the Symfony bundle applies the same rule.
