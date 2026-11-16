# A singleton resolves the caller's id once and runs the ordinary paths

A singleton type is routed at `/{uriType}` with no `{id}`, and `SingletonIdResolver` turns the request into the caller's id before the controller builds the operation, handing the type the PSR-7 request, the Illuminate request and the authenticated user. Every later step is the fetch, update, relationship or action an `{id}` route would run, so Eloquent global scopes, policies, hooks, the write transaction and the `PATCH` id check need no second, id-less path, and core's `OperationFactory` never resolves the id itself, which would hand the type the PSR-7 request alone.

## Consequences

- The resolved id is remembered on the Illuminate request, so it is resolved once per request even when an Atomic Operations batch addresses the singleton more than once. A batch resolves its `href`s through the same resolver, from the request it arrived on, before it writes anything.
- A singleton route's target carries no id, so route middleware and `$request->jsonApi()` see the type's path, as the URL names it.
- A singleton's data layer must expose only the caller's row, since its links always say `/{uriType}`. The package doesn't enforce it.
- Discovery reads a singleton from the resource class or the serializer that renders it, as core's registry does, so there is no `#[AsJsonApiResource]` option for it: an attribute could say singleton while core, reading the class, did not.
