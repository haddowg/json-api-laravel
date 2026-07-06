# An Eloquent filter self-applies via `AppliesToEloquentQueryBuilder`

- **Status:** accepted

An Eloquent-only filter can carry its own query fragment — a named scope, a `where`
closure, a relationship-existence clause — by implementing
`AppliesToEloquentQueryBuilder::applyToQueryBuilder(Builder, mixed)`. The
`EloquentFilterHandler` consults it **before** the arm registry (the built-ins still win),
so the filter runs with **no** `EloquentFilterArmInterface` registered.

**Why.** It is the self-applying twin of the arm seam — where an arm is a registered
service keyed on a filter's class, this puts the application on the filter value object
itself, so a one-off, dependency-free custom filter is fully defined by its own VO. It is
the *execution* counterpart of the `LaravelRules` carrier (ADR 0021) for validation:
paired with core's `DescribesQueryParameter` for a non-scalar `filter[…]` parameter shape,
a filter becomes wholly self-contained — value schema, OpenAPI shape, and execution — in
one class. Reach for an `EloquentFilterArmInterface` arm instead when the application needs
injected services (a repository, an auth guard). It mirrors the Symfony bundle's
`AppliesToQueryBuilder`.

## Consequences

`AppliesToEloquentQueryBuilder` runs only on the Eloquent provider — a filter that
implements it is not portable, so the same `filter[…]` key is undeclared on the in-memory
provider and a request there is a clean `400` (the unrecognised-filter boundary), never a
silent non-match. A filter that must run on both providers ships a portable
`FilterInterface` plus an arm per store instead. The handler gains one arm before its
`applyArm` fallback; no new binding or tag.
