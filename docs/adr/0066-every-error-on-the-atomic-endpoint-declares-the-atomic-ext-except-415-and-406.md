---
status: superseded by ADR 0067
---

# Every error on the atomic endpoint declares the atomic ext, except a 415 or 406

Only the rolled-back error from inside the batch used to carry `ext="https://jsonapi.org/ext/atomic"` on its `Content-Type`, so a failure raised before the batch ran, such as the empty-batch or parse `400`, a query-parameter `400`, the pre-flight `403`/`404` or a `405` on `/operations`, came back as plain `application/vnd.api+json` while the Symfony bundle declared the ext on all of them. The renderable now applies the ext to every error on the route carrying the `_jsonapi_atomic` marker, using the same exception-type rule as the bundle's ADR 0088: a `MediaTypeUnsupported` or `MediaTypeUnacceptable` means the extension was not negotiated, so those two omit it. Keeping the rule identical means a client sees the same `Content-Type` from either adapter for the same failure.
