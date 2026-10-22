# An empty base_uri follows the route prefix

Core bases links on the request origin when `base_uri` is empty, and it can't know that a
server's routes sit under a prefix such as `api`, so `GET /api/albums/1` linked to
`http://host/albums/1`, which 404s. `ServerRegistry::forRequest()` now gives core the
effective base `origin + request base path + the matched route's prefix` for each request,
through the existing `Server::withBaseUri()`, so resource, relationship, related, included,
pagination, error and `Location` links all carry the prefix once (document links rely on
core 1.1.1 dropping a base's path from the request path). This is the Laravel twin of the
Symfony bundle's ADR 0121.

## Considered options

- **Read the prefix from `jsonapi.servers`.** The config knows the prefix of an
  auto-registered server, but not of one placed with `Route::jsonApi()` inside the app's own
  group. Laravel keeps the group prefix on every route (`Route::getPrefix()`, and through
  `route:cache`), so the package reads it off the matched route and both placements behave
  the same. Unlike the bundle, nothing has to be recorded at route-compile time.
- **Build the base from a server's `domain`.** Not needed. When a domain-constrained route
  matched, the request host is that domain, and the origin carries the port and scheme the
  client actually used.

## Consequences

- An app relying on the old prefix-less links will see the prefix appear. Those links never
  resolved, so this is a fix, not a contract change.
- A configured `base_uri` is used verbatim and must include the prefix. Each entry in
  `jsonapi.servers` may now carry its own `base_uri`, falling back to the global one, since a
  single global base can only carry one server's prefix.
- A prefix with route parameters (`{tenant}/api`) is filled in from the matched route's
  parameters as the client sent them, before route-model binding.
- An app served below the web root adds its base path to an empty base, even with no
  prefix, matching the URLs Laravel's URL generator builds.
- The OpenAPI document advertises each distinct prefix of an empty-base server as a
  relative `servers` URL (`/api`), sorted, read from the router's routes rather than a
  request so a warmed document matches a lazily built one. A parameterised prefix becomes a
  server variable defaulting to the route default, which a server's `route_defaults` config key
  sets on every route; with no default the prefix is left out, because OAS requires one. The
  request base path and a proxy prefix are per request, so the document can't include them;
  `jsonapi.openapi.servers` covers that case.
