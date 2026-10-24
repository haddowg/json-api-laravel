# Built links point into the served OpenAPI document

Core renders the links it builds as link objects and gives each a `describedby` naming the operation that describes its target, from a resolver the adapter supplies (core ADR 0158). The package sets that resolver in `ServerRegistry::forRequest()`, pairing the server's document URL with the operations its document carries. The URL defaults to the package's own document route, generated against the request and trimmed to a host-relative path so it keeps any prefix and base path, and `jsonapi.openapi.document_url` replaces it for a document published elsewhere. The operations are `SpecDescribedByResolver::operationsOf()` of the document, written by the warmer beside it as a small path-to-methods map, so a request never builds the document to describe a link.

## Considered options

- Set the resolver once on the memoized Server. The default URL is generated against the request, and a long-running worker would freeze the first request's host and base path into every later one.
- Keep `DescribedbyStamper` pointing every response at the whole document. Core lets a `describedby` set through `withDescribedby()` win over its per-operation one, so stamping would hide every operation pointer, and with the atomic endpoint's path passed to `AtomicOperationsOperation` an Atomic Operations result now gets its own `POST` pointer. The stamper is gone.
- Generate the URL with Laravel's relative route URLs. Those drop the base path of an app served below the web root, so the absolute URL is generated and its scheme and host trimmed instead.

## Consequences

`link_objects` is a per-server setting like `output_timezone`, because it belongs to the Server that renders the links, while `describedby` and `document_url` sit with the rest of `jsonapi.openapi`, which has no per-server overrides; `{server}` in `document_url` covers per-server documents. In combined mode every server points into the one document, which describes the default server's link form. Without a warmed map the operations come from the stored document the docs controller serves, so the two never disagree, and only without either is the document built, once per process. The map is written back from a request only in debug, the rule the docs controller already follows, so production without `optimize` builds the document per process.
