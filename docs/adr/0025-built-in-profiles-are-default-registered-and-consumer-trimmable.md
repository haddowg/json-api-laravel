# Built-in JSON:API profiles are default-registered via `jsonapi.profiles` and consumer-trimmable

Core made the OpenAPI projection registration-aware (core ADR 0131, and the Symfony
bundle's parity twin): the projector reads the server's registered profile set
(`ServerMetadataInterface::profiles()`) and advertises profile-gated output — the
`jsonapi.profile` enum, the Countable `?withCount` parameter, the Relationship Queries
`relatedQuery` parameter, and the cursor page-schema marker — only for a **registered**
profile. The package previously hardcoded two profile registrations in `ServerFactory`
(Countable + Relationship Queries) and treated `jsonapi.profiles` as an *additional* list
(default `[]`), so cursor was never registered by default and the effective set/order
diverged from the bundle.

We remove the hardcoding and make `jsonapi.profiles` the **full** ordered set, defaulting
to the three built-ins (`CursorPaginationProfile`, `CountableProfile`,
`RelationshipQueriesProfile`) — exposed as `ServerFactory::DEFAULT_PROFILES` and used as
the config-absent fallback. The provider resolves the class-strings through the container
in order, `ServerFactory` registers exactly that list, and `MetadataSource` reads the live
registry off the built `Server` into `ServerMetadata::profiles()` — so the document
reflects what the server recognizes, never a hardcoded assumption. Trimming an entry drops
that profile's registration and its advertisement; appending a class recognizes a custom
profile.

The order is significant because it is the byte-order the `jsonapi.profile` enum is
generated in, so it is fixed identically to the Symfony bundle's `ServerFactory::DEFAULT_PROFILES`
— `composer byte-compat` proves every OpenAPI and JSON-Schema document stays byte-identical
across the two adapters. Folding cursor into the default means a cursor response now
advertises the cursor-pagination profile on its `Content-Type` and in `jsonapi.profile`;
the canonical `CursorConformanceTestCase` / `RelatedCursorConformanceTestCase` suites are
the witnesses (their page helpers assert the advertised profile URI, while a count-based
page selected from the same menu asserts its absence), and `ProfileRegistrationDocumentTest`
pins the default enum + `relatedQuery` component and that trimming `jsonapi.profiles` drops
a profile's enum entry and `relatedQuery` while `?withCount` survives.
