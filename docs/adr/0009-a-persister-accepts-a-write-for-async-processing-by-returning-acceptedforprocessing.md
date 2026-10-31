# A persister accepts a write for async processing by returning `AcceptedForProcessing`

JSON:API 1.1 describes asynchronous processing with a `202` and a later `303`, and the persister already owns the storage decision, so a `create()` or `update()` may return an `AcceptedForProcessing` marker instead of the entity; the handler renders it as core's `202 Accepted` response. This adds no SPI method, registry or interface, leaves synchronous writes untouched, and leaves how the work is queued to the application.

## Consequences

- An async accept can't join an Atomic Operations batch, since it defers the write past the batch's commit; it is a `422` that rolls the batch back.
- On a create, embedded relationships that apply after the insert are skipped, since nothing was keyed; the queued job owns them.
- `delete()` returns nothing, so there is no async delete.
