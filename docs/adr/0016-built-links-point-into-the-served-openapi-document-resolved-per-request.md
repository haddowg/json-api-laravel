# Built links point into the served OpenAPI document, resolved per request

Each link core builds carries a `describedby` naming the operation that describes its target, so the package sets core's resolver per request in `ServerRegistry::forRequest()`, pairing the document URL with a path-to-methods map the warmer writes beside the document. The default URL is the package's document route generated against the request; when the app routes `OpenApiController` under its own names the route is found by its controller at `optimize`, and `jsonapi.openapi.document_url` overrides both.

## Considered options

- **Set the resolver once on the memoized server.** A long-running worker would freeze the first request's host and base path into every later one.
- **Stamp every response with the whole document's URL.** A response-level `describedby` overrides the per-operation pointers.
