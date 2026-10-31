# A relation is routed by its URI field name and falls back to its name

Core keys a relation's paths by its URI field name, so the routed `{relationship}` segment and an atomic `href` resolve by it and a `RouteMatched` listener swaps the parameter for the relation's name, so the `Target`, `$request->jsonApi()` and route middleware agree. A segment that names no URI field is tried as a relation name before the `404`, because existing clients of a renamed relation call that path; the fallback is deliberately undocumented.

## Considered options

- **Serve the URI field name only.** Cleaner, but every existing client of a renamed relation would `404` on upgrade.
- **Translate in `TargetResolver`.** It has no server to look the relation up on, and middleware would still see the raw segment.
