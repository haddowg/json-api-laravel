# Capability composition

A JSON:API type is assembled from **independent, optional capabilities** — a serializer
(read), a hydrator (write), relations, a data provider (fetch), and a data persister (write).
`AbstractResource` is convenient sugar that bundles serializer + hydrator + relations + the
fields DSL into one declaration, but nothing is coupled to it. This page shows the seams; the
underlying model is core's (bundle ADR 0024, ported here as
[ADR 0011](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0011-standalone-serializer-capability.md)).

## The default: one resource, everything

Most types are a single `AbstractResource`. It supplies the read shape, the write shape, the
relations, and (via the [Eloquent layer](eloquent.md)) fetch + persist by convention. If you
need no writes, declare `readOnly: true` and no persister is used; if you need no reads at
all, a resource is the wrong tool — use a standalone serializer.

## A standalone serializer (no resource)

A type whose wire shape is fully hand-written, or that has no model at all, is registered
with `#[AsJsonApiSerializer]` on a class implementing core's `SerializerInterface`. It is
**serialize-only by default** — it renders as primary data, linkage and `included`, but
exposes no endpoints unless you allow-list operations:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApi\Serializer\SerializerInterface;

#[AsJsonApiSerializer(
    type: 'charts',
    operations: [Operation::FetchCollection, Operation::FetchOne],
)]
final class ChartSerializer implements SerializerInterface
{
    // getType(), getId(), getAttributes(), … — core's SerializerInterface
}
```

The example's `charts` and `countries` are exactly this: a standalone serializer plus a
custom [data provider](custom-data-providers.md) (a `ChartProvider` over a fixed list; a
`CountryProvider` sourcing rows from `symfony/intl`). Neither has a model — the
capability-composition witness.

`#[AsJsonApiSerializer]` takes `type`, `operations` (empty = serialize-only), `server`, and
`tags`.

## Dependency injection

Every capability class is constructed **through the container**, so it can take constructor
dependencies — a repository, a config value, a service. Bind a scalar constructor argument
the usual Laravel way:

```php
// In a service provider's register():
$this->app->when(ChartSerializer::class)
    ->needs('$catalogTag')
    ->give('music-catalog');
```

Discovery reads `::$type` statically at scan time and constructs the class lazily on first
use, so injected dependencies never run during discovery or `route:cache`.

## Customising the read shape without a separate class

Because `AbstractResource` *is* the serializer, you shape a read by overriding its methods or
by per-field closures — no separate serializer needed for most cases:

```php
Str::make('displayTitle')->computed()->readOnly()
    ->extractUsing(static fn(mixed $t): string => Accessor::get($t, 'track_number') . '. ' . Accessor::get($t, 'title'));

Map::make('releaseInfo')->nullable()
    ->serializeUsing(static fn(mixed $m) => Accessor::get($m, 'release_info') ?: null)
    ->fillUsing(static fn(mixed $m, mixed $v) => /* … write back … */ $m);
```

See [custom-serializers-hydrators](custom-serializers-hydrators.md) for the write side and
for handing one concern to a dedicated class with the per-resource
`#[AsJsonApiResource(serializer: …, hydrator: …)]` override.

## One model, two types

Two resource types may back the same model with different projections. The example maps both
the admin-only `users` and the public `public-profiles` to the `User` model:

```php
#[AsJsonApiResource(server: 'admin')]
final class UserResource extends AbstractResource
{
    public static string $type = 'users';
    // email, password (write-only), preferences, … — the full projection
}

#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class PublicProfileResource extends AbstractResource
{
    public static string $type = 'public-profiles';
    // displayName only — the private columns are simply never declared
}
```

The curation is the field inventory: a narrower type cannot leak a wider type's columns
because it never declares them. This is the storage-agnostic way to do "proxy resources" — no
new machinery. (The package forbids one *type* mapping to two *models*, not two types to one
model.)

## Registering providers and persisters

The fetch/persist capabilities are registered by priority — higher wins the first
`supports()` match. The reference Eloquent pair registers at `-128`, so any application
provider (default priority `0`) shadows it for the types it serves:

```php
JsonApi::provider(new ChartProvider());                 // priority 0 — shadows Eloquent
JsonApi::provider($eloquentProvider, priority: -128);   // the reference fallback
JsonApi::persister($eloquentPersister, priority: -128);
```

The SPI itself is on [custom-data-providers](custom-data-providers.md).
