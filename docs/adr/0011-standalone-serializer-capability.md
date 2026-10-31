# A type's serializer and hydrator are capabilities registrable without a resource

`AbstractResource` bundles a serializer, a hydrator and relations, but a type may need a hand-written wire shape or have no resource at all, so `#[AsJsonApiSerializer]` and `#[AsJsonApiHydrator]` register either half for a type on its own, and `#[AsJsonApiResource(serializer:, hydrator:)]` overrides one concern while the resource keeps the other. A standalone serializer opens no endpoints by default, unlike a resource, which opens all five; only its allow-list opens routes, and a hydrator adds write capability but never routes.

## Consequences

- A resource-less type has no field inventory, so its writes are not validated and its OpenAPI attributes are a permissive object. Keep a resource and override one concern when validation or a typed document matters.
- An allow-list that opens `Create`/`Update` with no hydrator fails route registration.
