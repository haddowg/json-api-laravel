# The served OpenAPI document is decorated per request from serialized value objects

One deployment serving many hosts or tenants needs per-request `servers` and pruned operations, but build-time decorators can't see the request. Serve decorators run in the docs controllers on value objects the warmer PHP-serializes beside the JSON, so the expensive build still happens at `optimize`; the CLI exporters and static files stay undecorated as the full contract. Decorators only remove, and the package prunes what they orphan, so app code never walks `$ref`s.

## Considered options

- **A `fromArray()` hydrator for core's value objects.** A second hand-maintained copy of every value object's serialisation, run on every request.

## Consequences

- The serialized artifact records the installed versions and is treated as missing when they change, and it unserializes only allow-listed classes.
- The ETag is the artifact hash with no decorator, a hash of each decorator's cache key when all supply one, and otherwise the hash of the final body. `cache_control` is off by default because docs routes are often behind authentication.
