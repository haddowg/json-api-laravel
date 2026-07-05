# The Eloquent data layer

The package ships a reference **Eloquent** `DataProvider` + `DataPersister` pair. Any resource
whose `$type` maps to a model reads and writes with no data code of your own — the
Laravel-native equivalent of the Symfony bundle's Doctrine layer. This page covers the
reference layer; the storage-agnostic SPI it implements is on
[custom-data-providers](custom-data-providers.md).

## Wiring the model map

The reference pair is registered at the lowest priority (`-128`), so any application provider
shadows it. Register it from a service provider's `register()`, mapping each JSON:API type to
its model:

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

Unknown `?filter`/`?sort` keys are a `400` (stricter than the spec's silent-ignore — see
[configuration](configuration.md#strict_query_parameters)). Filter *values* that fail
coercion are a `400`/`422`, not a database error (see [validation](validation.md)).

### Custom filter arms

Extend the vocabulary with a filter arm — a class the provider consults for a filter it
doesn't recognise. The example's `FullTextSearch` (`filter[q]` across several columns) ships
an Eloquent arm and an in-memory arm, wired via the provider's `filterArms:`:

```php
JsonApi::provider(
    new EloquentDataProvider($modelByType, filterArms: [new EloquentFullTextSearchArm()]),
    priority: -128,
);
```

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
