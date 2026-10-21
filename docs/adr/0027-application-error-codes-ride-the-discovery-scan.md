# Application error codes ride the discovery scan

Core's projected error catalogue is now assembled from contributed
`ErrorCatalogSourceInterface`s rather than a fixed roster, offered through the
`ContributesErrorCodes` server-metadata seam (core ADR 0136). The package reaches an
application's described errors with the machinery it already has: `DiscoveryScanner`
classifies a `DescribedErrorInterface` implementation into its own bucket, `Discovery`
memoizes it, and it rides the `jsonapi:optimize` snapshot like every other discovered
class-string — so a `route:cache`d app documents exactly the codes a scanning one does.
`JsonApi::register()` names a class no scan reaches, which is the same escape hatch it
already is for a resource.

Discovery walks the configured `jsonapi.discovery.paths` and nothing else. An unscoped
walk of the app would publish whatever described error happened to exist — a test
fixture, a scratch class — as part of the API's contract, which is a defect that surfaces
in a client's generated code rather than in CI.

## Consequences

Contributed codes are documented on **every** declared server. The core seam is
per-server, but an exception class carries no server affinity the way a resource does, and
the Symfony bundle makes the same call, so a per-server split would be byte-parity risk
bought with nothing.

Scanned classes are ordered by case-insensitive class name (core's own `CoreErrorSource`
rule), explicitly registered ones follow in the order named. That fixes the emit order of
the projected error components, which the byte-identical-document guarantee depends on:
the Symfony bundle's compiler pass sorts its scan the same way.
