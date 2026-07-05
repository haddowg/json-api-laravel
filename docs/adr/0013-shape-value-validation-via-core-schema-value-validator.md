# The validation bridge value-validates a `Shape` through the core `SchemaValueValidator`

- **Status:** accepted

The always-on validation bridge (`ResourceValidator`) validates an attribute carrying
a `Shape` composite-schema constraint (core ADR 0120 — `oneOf`/`anyOf`/`allOf` of raw
member schemas) by delegating to the core `SchemaValueValidator` (core ADR 0121),
which runs the constraint's compiled `Schema` against the value with opis and returns
one `422` `Error` per leaf violation, each pointer being `/data/attributes/<field>`
plus the opis instance pointer. Like `OneOf` and the cross-field `CompareField`s, it
runs as a **document-level pass** (a whole field value against its composite schema),
not through the per-field rule map, and is skipped there
(`ResourceValidator::valueConstraints` `continue`s on a `Shape`) — the Laravel twin of
the Symfony bundle's ADR 0112.

**Why.** A `Shape` carries *raw JSON Schema* no illuminate rule can translate — opis
is its only validator, and that execution is framework-agnostic, so it lives in core
rather than being re-derived in this package and the bundle (core ADR 0121). The
bridge's job shrinks to: resolve each `Shape`-constrained field, compile its `Schema`,
hand value + schema to the one core validator, and fold the returned `Error`s into
the same `422` response every other attribute violation produces.

## Consequences

opis/json-schema is a dev/`suggest` dependency, so the core `SchemaValueValidator` is
bound and injected into `ResourceValidator` only when `\Opis\JsonSchema\Validator`
exists, and `null` otherwise — matching the optional posture of the testing kit's
schema assertions. When opis is absent a `Shape` still projects its OpenAPI shape but
is not value-validated. Witnessed end-to-end over HTTP by
`CompositeConformanceTestCase` against both providers (a valid discriminated-`oneOf`
create, a variant missing a required member, an unknown discriminator — all pointed
under `/data/attributes/contact`).
