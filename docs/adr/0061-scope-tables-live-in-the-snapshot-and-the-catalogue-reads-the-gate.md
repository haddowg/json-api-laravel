# Scope tables live in the discovery snapshot, and the catalogue reads the Gate

A server's OAuth scope table is resolved once from the app's resolver, so it has to be kept somewhere a request can load it without asking the resolver again. `jsonapi:optimize` builds every server's table before anything else, fails the deploy when a build fails, and writes the tables into the discovery snapshot, because that is the one file a production app already loads. Without a snapshot the table goes in the cache store, keyed by a fingerprint of the discovered declarations, their files' modification times and the resolver's file, so editing a resource in development builds a new table rather than serving a stale one. The scope services are bound in the app's container rather than named in config, so `config:cache` still works.

## Consequences

The server is built before its enforcer, because building the table needs the server's metadata, which reads the server back. `ServerFactory` memoizes the unenforced server, builds the enforcer, and only then memoizes the enforced one. If building the enforcer throws, the memo is cleared, so no later caller gets a server that does not check scopes.

The catalogue's evaluator answers from the Gate. `Gate::forUser()->inspect()` decides an ability whose policy method or `Gate::define()` callback takes only the user. One that also takes the resource answers `Depends`, read from the method's or closure's signature, and so does any policy with a `before()` method, since `before()` can decide differently for each resource. An ability defined as `[Policy::class, 'method']` is wrapped by the Gate in a closure that takes anything, so the evaluator reads the wrapped method and policy instead.

The atomic backend checks each operation's scopes in the same pre-flight as the exposure gate and the transactional persister check, before `begin()`, so a refused batch never opens a transaction. Each operation is checked as the equivalent direct request would be, with the relation name resolved from an `href` the same way the target is.
