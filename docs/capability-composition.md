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

## A standalone hydrator (no resource)

The write half is registered the same way: `#[AsJsonApiHydrator]` on a class implementing
core's `HydratorInterface` registers the type's hydrator with no resource. A standalone
type is writable exactly when a hydrator is registered for it (and a
[persister](custom-data-providers.md) is wired) — no hydrator means no writes:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

#[AsJsonApiHydrator(type: 'beacons')]
final class BeaconHydrator implements HydratorInterface
{
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        // read the request document's attributes onto the domain object
        $label = $request->getResourceAttribute('label');
        // … assign, then:
        return $domainObject;
    }
}
```

`#[AsJsonApiHydrator]` takes `type` and `server` — deliberately **no** `operations`: a
hydrator adds write *capability*, never endpoints. Endpoints are opened only by the paired
serializer's allow-list (or a resource's), so a hydrator registered alone exposes nothing —
it is the write shape core resolves through `hydratorFor()`, e.g. for a
[custom action](actions.md)'s decoupled `inputType` document.

A class may carry more than one of these attributes: a single class that implements both
`SerializerInterface` and `HydratorInterface` can bear `#[AsJsonApiSerializer]` and
`#[AsJsonApiHydrator]` together, registering both halves of a resource-less type in one
place.

## The default-operations asymmetry

This is the one footgun to internalise. **A standalone serializer exposes no endpoints by
default; an `AbstractResource` exposes all five.**

| Type kind | Default operations |
| --- | --- |
| `AbstractResource` | all five (`FetchCollection`, `FetchOne`, `Create`, `Update`, `Delete`) |
| standalone `#[AsJsonApiSerializer]` | **none** — serialize-only |
| standalone `#[AsJsonApiHydrator]` | **none** — a hydrator never opens endpoints |

A standalone serializer defaults to serialize-only because the classic use is an
*embedded/reference* type: it renders as primary data, linkage and `included` when it
appears inside another resource, but serves no routes of its own. To give it endpoints you
open them explicitly with the `operations` allow-list — the example's `charts` opens
exactly `GET /charts` and `GET /charts/{id}`; a hydrator-paired type may open the write
verbs the same way (`Create` → `POST`, `Update` → `PATCH`, `Delete` → `DELETE`).

## Mix-and-match recipes

Because every capability is optional and independent, the endpoint set is whatever the
capabilities you declare add up to:

| You want | Declare |
| --- | --- |
| a serialize-only embedded/reference type | a serializer alone (no `operations`) |
| a read-only fetchable type | a serializer with `operations: [FetchCollection, FetchOne]` + a provider |
| a write shape with no endpoints (e.g. an action `inputType`) | a hydrator alone |
| a fully resource-less CRUD type | serializer (all five `operations`) + hydrator + provider + persister |

The `charts` type is the second row, lifted straight from the example. The fourth row
needs no resource at all: the serializer and hydrator (two attributes, possibly on one
class) plus a provider/persister pair give you the same CRUD endpoints an
`AbstractResource` would — assembled from independent parts instead of one declaration.
Relations stay resource-only: a resource-less type declares none, so it never gets the
related/relationship routes.

## The write-capability guard

You cannot expose a write without something to populate the entity. If a standalone type's
`operations` allow-list includes `Create` or `Update` but no hydrator is registered for it,
**route registration fails** with a `LogicException` naming the type and the fix:

```
The JSON:API type "charts" exposes a write operation (Create) but has no hydrator;
register #[AsJsonApiHydrator(type: "charts")] or use an AbstractResource.
```

This surfaces at boot (and at `route:cache` / `jsonapi:optimize` — a deploy step), never
as a runtime surprise on the first request. `Delete` hydrates nothing, so it needs no
hydrator — only a persister, which the [servability guard](optimize.md) holds it to. (An
`AbstractResource` always carries a hydrator, so it never trips this.)

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
