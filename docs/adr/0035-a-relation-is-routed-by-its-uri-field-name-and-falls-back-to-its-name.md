# A relation is routed by its URI field name and falls back to its name

Core 1.7 keys a relation's related and relationship paths by its URI field name (core ADR 0161), so the package now resolves the routed `{relationship}` segment, and an Atomic Operations `href`, with `AbstractResource::relationAtUriFieldName()`, and a `RouteMatched` listener swaps the route parameter for the relation's name as soon as the router matches, so the `Target`, `$request->jsonApi()` and route middleware all see the name. When no relation is served under the segment, it is tried as a relation name before the request `404`s, because a renamed relation used to be served only under its name and existing clients still call that path. The fallback is deliberately undocumented: the links and the OpenAPI document name only the URI field name. This matches the Symfony bundle (its ADR 0133).

## Considered options

- Serve the URI field name only, as core's ADR assumes. It is cleaner, but every existing client of a renamed relation would get a `404` on upgrade.
- Translate the segment in `TargetResolver`. It is a pure mapper with no server to look the relation up on, and `$request->jsonApi()` and route middleware would still see the segment, disagreeing with the handler about which relation the request names.
