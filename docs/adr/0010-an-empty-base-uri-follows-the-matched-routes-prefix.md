# An empty `base_uri` follows the matched route's prefix

Core bases links on the request origin when `base_uri` is empty and cannot know that a server's routes sit under a prefix, so its links 404'd. The package gives core `origin + request base path + the matched route's prefix` per request, read off the matched route rather than the config, so a server placed with `Route::jsonApi()` inside the app's own group behaves like an auto-registered one. Every link core builds, action links included, takes that base.

## Consequences

- A configured `base_uri` is used verbatim and must include the prefix, so each server may carry its own.
- The OpenAPI document lists each prefix as a relative `servers` URL read from the router, since the request base path and a proxy prefix are per request.
