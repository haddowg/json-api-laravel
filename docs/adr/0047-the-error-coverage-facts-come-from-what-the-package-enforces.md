# The error coverage facts come from what the package enforces

Core offers an error code only on the operations whose runtime can raise it, and asks the adapter for the facts only it knows. The package answers each one from the code path that raises the code: `ACCESS_DENIED` from the abilities the `Authorizer` enforces and the middleware on the operation's route, `AUTHENTICATION_REQUIRED` from that middleware alone, since a policy refuses with `403` whoever calls, the detachment fact from the foreign key's column in the database, and the include safeguards from the serializer the server renders the type with. Where a fact depends on code or state the package can't read, such as a middleware it doesn't know, a persister of the application's own or a table that isn't there at export time, it answers that the code can arise, because a missing code hurts a client more than a spare one.

## Considered options

- Treating every middleware as one that can refuse: `SubstituteBindings` or a throttle would then document `401` on every route, so the framework's request plumbing and the package's own middleware count for nothing, `auth` for `401` and `can` for `403`.
- Letting a linkage id that isn't an integer through to an integer key: the Symfony bundle answers it `404 RESOURCE_NOT_FOUND`, and the two must document the same codes, so the Eloquent persister now refuses it the same way.
- Accepting a create or update whose `attributes`, `relationships` or `meta` is not an object: the bundle refuses it with `400 REQUEST_BODY_INVALID_JSON_API`, and silently ignoring the member drops what the client sent, so the handler now refuses it too.

## Consequences

The byte-compat export migrates the workbench schema, so its nullable foreign keys document no detachment refusal, and the workbench catalogue runs a bearer-token middleware, as the bundle example's firewall does.
