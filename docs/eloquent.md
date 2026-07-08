# The Eloquent data layer

The package ships a reference **Eloquent** `DataProvider` + `DataPersister` pair. Any resource
whose `$type` maps to a model reads and writes with no data code of your own — the
Laravel-native equivalent of the Symfony bundle's Doctrine layer. This page covers the
reference layer; the storage-agnostic SPI it implements is on
[custom-data-providers](custom-data-providers.md).

## The model map (three tiers)

Each type resolves to its model through three tiers — first match wins per type
([ADR 0019](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0019-three-tier-model-mapping-with-an-auto-registered-reference-pair.md)):

1. **Explicit wiring** — a `JsonApi::provider()`/`persister()` registration (below). It
   always wins for the types it `supports()`, whatever its priority: the auto-registered
   pair sits at `-256`, below the documented explicit floor of `-128`.
2. **The `model:` declaration** — `#[AsJsonApiResource(model: Album::class)]`, for a type
   whose model name diverges from convention (or two types sharing one model, which
   convention can never guess). Validated at scan time (the class must exist and extend
   `Model`) and carried through the [`jsonapi:optimize`](optimize.md) snapshot.
3. **The convention guess** — the kebab/plural type, singularized and studly-cased under
   `jsonapi.eloquent.model_namespace` (default `App\Models`): `albums` → `App\Models\Album`,
   `public-profiles` → `App\Models\PublicProfile`. Claimed only when that class exists and
   is an Eloquent model; set the namespace to `null` to disable the tier.

The package builds one `type → model` map from tiers 2 + 3 and **auto-registers the
reference provider/persister pair over it** — the zero-config path the
[getting-started](getting-started.md) page walks; the Symfony bundle's
`#[AsJsonApiResource(entity: …)]` twin. The pair claims *only* the mapped types: a type no
tier resolves still fails with the no-provider wiring error, never a silent wrong guess.

### Explicit wiring

For full control (a non-default connection, constructor
[arm](#custom-filters-and-sorts-the-arm-seam) instances, or shadowing the auto pair),
register the pair from a service provider's `register()`, mapping each JSON:API type to its
model:

```php
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\Facades\JsonApi;

$modelByType = [
    'albums'  => \App\Models\Album::class,
    'artists' => \App\Models\Artist::class,
    'tracks'  => \App\Models\Track::class,
];

JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
```

`-128` keeps the reference-pair convention — any application provider at the default
priority (`0`) shadows it, and it in turn shadows the `-256` auto pair.

The provider owns the base query per type; the persister owns create/update/delete inside a
`DB::transaction`, returning `201` + `Location` on create, `200` on update, `204` on delete.

## Filters → query builder

Core's filter vocabulary is pushed down to the Eloquent builder. The example's resources
declare, and the provider translates:

| Filter | Becomes |
| --- | --- |
| `Where::make('slug')` | `where('slug', …)` (operator via the 3rd ctor arg, e.g. `'like'`, `'>'`) |
| `WhereIn::make('genres')` | `whereIn(...)` |
| `Contains::make('title')` | `where('title', 'like', "%…%")` |
| `Range::make('rating', 'average_rating')` | a `>=`/`<=` bound pair |
| `DateRange::make('releasedAt', 'released_at')` | a datetime bound pair |
| `WhereHas::make('tracks')` | `whereHas('tracks')` (relationship existence) |
| `WhereThrough::make('artist.name')` | a dotted-path correlated `EXISTS` |

Need an operator or a sort the built-ins don't cover? Author a custom `FilterInterface` /
`SortInterface` and teach the provider to push it down via the
[**arm seam**](#custom-filters-and-sorts-the-arm-seam) — no need to replace the whole
provider.

Unknown `?filter`/`?sort` keys are a `400` (stricter than the spec's silent-ignore — see
[configuration](configuration.md#strict_query_parameters)). Filter *values* that fail
coercion are a `400`/`422`, not a database error (see [validation](validation.md)).

## Custom filters and sorts (the arm seam)

The built-in handlers cover core's filter/sort vocabulary; for a **custom**
`FilterInterface` or `SortInterface` of your own, register an **arm** — a small class the
handler consults for value objects it does not recognise, before raising core's
`UnsupportedFilter` / `UnsupportedSort`. (When a custom VO reaches the handler with no arm
to run it, that `500` even names the seam: its message ends "…register an
`EloquentFilterArmInterface` on the `EloquentDataProvider` (constructor `$filterArms`)".)
The built-ins always win — an arm is a fallthrough, never an override of `Where`/`SortByField`.

- An [`EloquentFilterArmInterface`](https://github.com/haddowg/json-api-laravel/blob/main/src/DataProvider/Eloquent/EloquentFilterArmInterface.php)
  is `supports(FilterInterface): bool` plus
  `apply(FilterInterface $filter, Builder $query, mixed $value): void` — push your predicate
  down with `where(...)`, parameter-bound. Keyed on the filter's concrete type
  (`$filter instanceof MyFilter`), one arm per filter class.
- An [`EloquentSortArmInterface`](https://github.com/haddowg/json-api-laravel/blob/main/src/DataProvider/Eloquent/EloquentSortArmInterface.php)
  is `supports(SortInterface): bool` plus
  `apply(SortInterface $sort, Builder $query, bool $descending): void` — append your term
  with `orderBy`/`orderByRaw` (which append), never a call that discards the earlier
  directives, so a custom directive participates in the composite `ORDER BY`.

Register arms on the reference provider via its `filterArms:` / `sortArms:` constructor
arguments:

```php
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;

JsonApi::provider(
    new EloquentDataProvider(
        $modelByType,
        filterArms: [new EloquentFullTextSearchArm()],
        sortArms: [new OrderByRelevanceArm()],
    ),
    priority: -128,
);
```

The [auto-registered pair](#the-model-map-three-tiers) picks arms up from tagged container
bindings instead — no hand-constructed provider needed:

```php
use haddowg\JsonApiLaravel\JsonApiServiceProvider;

$this->app->singleton(EloquentFullTextSearchArm::class);
$this->app->tag(EloquentFullTextSearchArm::class, JsonApiServiceProvider::ELOQUENT_FILTER_ARM_TAG);
$this->app->tag(OrderByRelevanceArm::class, JsonApiServiceProvider::ELOQUENT_SORT_ARM_TAG);
```

The example's `FullTextSearch` (`filter[q]` across several columns) ships an
`EloquentFullTextSearchArm` (the SQL push-down) **and** an `ArrayFullTextSearchArm` (the
in-memory twin), so the two stay behaviourally identical under the shared conformance suite.
For a **portable** custom filter/sort that must also run on the in-memory witness, ship both:
the Eloquent arm here and core's
[`ArrayFilterArmInterface`](https://github.com/haddowg/json-api/blob/main/src/Resource/Filter/InMemory/ArrayFilterArmInterface.php)
/ [`ArraySortArmInterface`](https://github.com/haddowg/json-api/blob/main/src/Resource/Sort/InMemory/ArraySortArmInterface.php)
(passed to the `InMemoryDataProvider` constructor's `filterArms:`/`sortArms:`). An inherently
Eloquent-specific filter (a raw scope) ships only the Eloquent arm. A custom provider that
needs its own filter/sort translation is covered in
[custom data providers](custom-data-providers.md#custom-filters-and-sorts).

### Self-applying filters: no arm to register

For a one-off, dependency-free Eloquent filter, skip the separate arm and put the query
fragment on the filter VO itself by implementing
[`AppliesToEloquentQueryBuilder`](https://github.com/haddowg/json-api-laravel/blob/main/src/DataProvider/Eloquent/AppliesToEloquentQueryBuilder.php):

```php
final readonly class Active implements AppliesToEloquentQueryBuilder
{
    public function key(): string { return 'active'; }
    public function constraints(): array { return [new Boolean()]; }

    public function applyToQueryBuilder(Builder $query, mixed $value): void
    {
        $query->whereNull('archived_at'); // a named scope, a where closure, a whereHas…
    }
}
```

The handler consults it **before** the arm registry, so it runs with no
`EloquentFilterArmInterface` registered — the self-applying twin of the arm seam, and the
query counterpart of the [`LaravelRules`](validation.md#native-laravel-rules-without-a-translator-laravelrules)
validation carrier. Reach for an arm instead when the application needs injected services
(a repository, an auth guard). Like a raw scope it is Eloquent-only — the key is undeclared
on the in-memory provider, so a request there is a clean `400`. Bind the request value with
`where(...)` (never interpolate it); only ever interpolate a validated, server-declared
column into a `whereRaw()`. The shipped `WithTrashed`/`OnlyTrashed`
[soft-delete filters](soft-deletes.md) are self-applying filters of exactly this shape.

## Soft deletes

A resource whose model uses Laravel's `SoftDeletes` trait can opt into first-class soft
deletes with `#[AsJsonApiResource(softDeletes: true)]` — `DELETE` becomes a recoverable soft
delete and the package synthesizes `restore`/`force-delete` actions. See
[soft deletes](soft-deletes.md).

## Sorting, sparse fieldsets, pagination

`sortable()` fields drive `?sort` (`SortByField::make($key, $column)` maps a wire key to a
column); `defaultSort()` orders an unsorted collection. Sparse fieldsets narrow the selected
columns. Page / offset / cursor (keyset) pagination all push down — see
[pagination](pagination.md).

## `?include` batching — SPI, not `with()`

Compound documents are assembled by a provider-agnostic **batcher** over the SPI, not by
top-level Eloquent `with()`
([ADR 0005](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0005-include-batching-orchestrates-via-the-spi-not-eloquent-with.md)):
top-level `with()` cannot return the per-parent totals / `hasMore` a windowed relationship
needs. The Eloquent provider *implements* the batch seams using Eloquent's own eager-load
internals (`addEagerConstraints` + `getEager` + dictionary matching, a `BelongsTo`
FK-projection for the to-one arm), then writes the result back with `$parent->setRelation()`
— so `relationLoaded()` is true and the load-state seam renders linkage without a second
fetch.

## Windowed relationship queries — SQL push-down only

Per-parent relationship paging (the Relationship Queries profile, `?withCount`, a related
collection with its own `page[]`) is a **SQL push-down only** — no toggle, no PHP fallback
([ADR 0006](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0006-windowed-relation-batches-are-sql-push-down-only.md),
PLAN decision 9). It uses Eloquent's relation `limit()` → `Builder::groupLimit()` →
`ROW_NUMBER() OVER (PARTITION BY …)` with the relation's order plus a deterministic id
tie-breaker; `hasMore` is an N+1 probe on the same query; countable totals are a grouped
`COUNT`. Every first-party Laravel driver has window functions, so the Doctrine toggle's
portability rationale does not transfer. The in-memory witness runs core's `WindowExecutor`
with the same id tiebreak, so the conformance suite referees SQL vs PHP windowing on every
run.

## Polymorphic relations and the morph map

The Eloquent morph **alias** (the `Relation::morphMap()` value) is decoupled from the JSON:API
**type**
([ADR 0007](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0007-morph-alias-is-decoupled-from-the-json-api-type.md)):
the alias only picks the model class when Eloquent hydrates a morph relation; the wire type is
resolved from each member object's serializer `getType()`. So a stored alias may differ freely
from the rendered type, and renaming aliases is a storage migration that never touches the API.

```php
Relation::morphMap(['mc_track' => Track::class, 'mc_album' => Album::class, 'mc_artist' => Artist::class]);
```

The example's `favorites.favoritable` (`morphTo`) and `libraries.items` (`morphedByMany`, the
**over-parity** polymorphic to-many the Doctrine reference throws on) both run on this
reference provider. See [relationships](relationships.md#polymorphic-relations).

## Eloquent model events still fire

The persister calls `$model->save()` / `$model->delete()`, so **Eloquent model events fire
untouched** — a `saving`/`saved`/`deleting` observer runs on any write path, including
API-driven ones. The package's own [lifecycle events](lifecycle.md) are distinct: they carry
JSON:API operation context and defer After* work post-commit. Use model events for
persistence concerns, JSON:API events for API concerns.

> [!NOTE]
> Inside an [atomic operations](atomic-operations.md) batch, model events fire **mid
> transaction** (on each `save()`), while the JSON:API After* events defer to after the batch
> commits. Keep side effects that must not fire on a rolled-back operation in an After* hook.
