# Custom data providers and persisters

Data access runs through a storage-agnostic **service-provider interface (SPI)**: a
`DataProviderInterface` (read) and a `DataPersisterInterface` (write), resolved per type by a
first-`supports()` match in descending priority order. The [Eloquent layer](eloquent.md) is
the reference implementation (auto-registered at `-256` from the
[model-map tiers](eloquent.md#the-model-map-three-tiers), or hand-wired at `-128`); register
your own at a higher priority to
serve a type from anything — an external API, a fixed list, `symfony/intl`, a legacy store
(bundle ADR 0002, ported here as
[ADR 0002](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0002-port-the-provider-persister-spi.md)).

## The read interface

```php
interface DataProviderInterface
{
    public function supports(string $type): bool;
    public function fetchOne(string $type, string $id): ?object;
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult;

    // Relationship reads (defaulted in AbstractDataProvider — override only what you need):
    public function fetchRelatedCollection(/* … */): CollectionResult;
    public function fetchRelatedCollectionBatch(/* … */): array;   // ?include batching
    public function countRelated(/* … */): array;                  // ?withCount
    public function relatedToOneMatches(/* … */): bool;
    public function relatedToOneMatchesBatch(/* … */): array;
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array;
}
```

`fetchCollection()` receives a `CollectionCriteria` (the parsed filters, sorts, pagination
window) and returns a `CollectionResult` (the page of objects + total/`hasMore`). The
relationship seams power `?include` batching, `?withCount`, relationship-existence checks, and
pivots — the batcher orchestrates them provider-agnostically ([ADR 0005](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0005-include-batching-orchestrates-via-the-spi-not-eloquent-with.md)).

A provider that can filter and sort a `belongsToMany` on its join columns also implements
`QueriesPivotColumnsInterface`. Its `fetchRelatedCollection()` then receives the relation's
`pivot.`-columned filters and the pivot field sorts, and `pivotMapOf()` reads each fetched
member's pivot values for `meta.pivot` without another query. Without it, pivot sort and filter
keys are a `400` and `meta.pivot` comes from `fetchRelationshipPivot()`.

## `AbstractDataProvider` — start here

Extend `AbstractDataProvider` and you inherit neutral defaults for every relationship seam, so
a simple read-only provider implements only `supports()` + the two fetch methods. The
example's `ChartProvider` delegates to an in-memory provider over a fixed list:

```php
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;

/** @extends AbstractDataProvider<object> */
final class ChartProvider extends AbstractDataProvider
{
    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('charts', [/* fixed rows … */]);
    }

    public function supports(string $type): bool { return $type === 'charts'; }
    public function fetchOne(string $type, string $id): ?object { return $this->inner->fetchOne($type, $id); }
    public function fetchCollection(string $type, $criteria): mixed { return $this->inner->fetchCollection($type, $criteria); }
}
```

The example's `CountryProvider` is the same shape over `symfony/intl` `Countries`, declaring
its own `filter[name]`, `sort=name`, and offset-window pagination — a reference-data witness
with no database.

## The in-memory provider

`InMemoryDataProvider` is a full SPI implementation over an in-memory object store. It ships as
a test double and the **conformance witness** — every conformance test runs against both it
and the Eloquent provider, so a divergence is caught on every run. It is also the honest way
to serve a small fixed dataset. Construct it with the seed objects and (for writable stores)
id accessor closures:

```php
$provider = new InMemoryDataProvider('genres', $seedRows, identify: $idAccessor);
JsonApi::provider($provider);
JsonApi::persister(new InMemoryDataPersister('genres', $provider->store(), fn() => new Genre()));
```

## The write interface

```php
interface DataPersisterInterface
{
    public function supports(string $type): bool;
    public function instantiate(string $type): object;                 // a blank entity for create
    public function create(string $type, object $entity): object;
    public function update(string $type, object $entity): object;
    public function delete(string $type, object $entity): void;
    public function mutateRelationship(/* … */): object;               // relationship PATCH/POST/DELETE
}
```

`instantiate()` exists because the persister owns the storage mapping. `mutateRelationship()`
applies a relationship endpoint write. Implement `TransactionalDataPersisterInterface` to wrap
a write (and its deferred After* work) in a transaction — the Eloquent persister does this
over `DB::transaction`.

If your store has relations where a related row has one parent (a has-many over a foreign key
on the related row), implement `ReportsCurrentOwners`. Its `currentOwners()` returns the
incoming linkage members another parent holds now, and the handler then checks `update` on
that parent (or the row) before the write moves it. Without it, rows move unchecked.

## Priority and shadowing

Providers and persisters register with a priority; the first that `supports()` a type wins.
The reference Eloquent pair sits at the bottom (`-256` auto-registered, `-128` hand-wired),
so an application registration (default `0`) shadows it for its own types while everything
else falls through to Eloquent:

```php
JsonApi::provider(new ChartProvider());                       // priority 0
JsonApi::provider($eloquent, priority: -128);                 // the fallback
```

This is how the example serves `charts`/`countries` from custom providers while every model
type falls through to Eloquent, and how a `libraries` polymorphic-to-many can be served by a
custom provider if you don't use the reference morph resolution.

## A provider that scopes its rows

A provider that owns a type decides which of its rows exist for a request, for example by
dropping rows the user may not see from `fetchOne()`, `fetchCollection()` and
`fetchRelatedCollection()`. Those rows stay hidden wherever the type is rendered from another
type's document. When a to-many relates to a type served by its own provider (not the parent's,
and not the reference Eloquent or in-memory provider), its members load through that provider's
`fetchRelatedCollection()` for every parent that renders them. That covers `?include`, nested
includes, linkage rendered without an include, `?withCount` totals, the Relationship Queries
window and the relationship endpoint, so each shows exactly what the related endpoint shows.

These loads run once per parent rather than batched. An Eloquent global scope needs none of
this: Eloquent applies it to every relation read already.

A to-one target of such a type is read off the parent and kept only while the provider's
`fetchOne()` still returns it. Otherwise the relation renders as empty everywhere: linkage,
`included`, the relationship and related endpoints, a `MorphTo`, and any attribute flattened
through it with `on()`. Writing such an attribute through a hidden target is a `422`.

A polymorphic to-many that reaches such a type has no single provider to read through, so
rendering its members fails with a server error rather than showing unscoped rows. See
[ADR 0032](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0032-a-related-type-with-its-own-provider-loads-its-members-through-it.md).

Writes go through the same provider. A persister looks related ids up in its own storage, so
before it applies a relationship write, each id the client names for such a type must come back
from the provider's `fetchOne()`. One that doesn't is a `404`, the same response as an id with
no row behind it, so a client can't tell the two apart. This covers create and update bodies,
`PATCH`/`POST`/`DELETE` on a relationship endpoint, pivot members with their `meta.pivot`, a
`MorphTo`, and every operation in an atomic batch, where the `404` rolls the whole batch back.
A to-many replace changes only the members the client can see: the persister is handed the
hidden current members as well, so they stay attached with their pivot rows untouched. The
response to a relationship write lists the members the relationship endpoint would. See
[ADR 0034](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0034-a-relationship-write-resolves-related-ids-through-the-related-types-scope.md).

## Custom filters and sorts

A `DataProviderInterface` owns how it translates core's filter/sort vocabulary for its store:
`fetchCollection()` receives the parsed filters/sorts on the `CollectionCriteria` and applies
them however the backend requires (or rejects an unsupported one with core's
`UnsupportedFilter`/`UnsupportedSort`). The [reference Eloquent provider](eloquent.md)
composes core's `FilterHandlerInterface` / `SortHandlerInterface` and extends that vocabulary
through an **arm seam** — register an `EloquentFilterArmInterface` / `EloquentSortArmInterface`
for a custom `FilterInterface` / `SortInterface` and the reference provider pushes it down
without you replacing the provider. See
[the Eloquent arm seam](eloquent.md#custom-filters-and-sorts-the-arm-seam).
