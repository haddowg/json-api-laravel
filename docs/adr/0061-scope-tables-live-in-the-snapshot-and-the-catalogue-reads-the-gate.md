# OAuth scope tables live in the discovery snapshot, and the catalogue reads the Gate

A server's scope table is resolved once from the app's resolver and must load per request without asking it again, so `jsonapi:optimize` builds every table, fails the deploy if one fails, and writes them into the discovery snapshot, the one file a production app already loads. Without a snapshot the table is cached under a fingerprint of the discovered declarations and the resolver's file, so a development edit never serves a stale table.

## Consequences

- The server is memoized unenforced while its enforcer is built, and the memo is cleared if that throws, so no caller gets a server that skips scopes.
- The catalogue answers from `Gate::forUser()->inspect()`, and reports `Depends` for an ability that also takes the resource or a policy with `before()`.
- Atomic batches check every operation's scopes before the transaction opens.
