# Custom data providers and persisters

Data access runs through a storage-agnostic **service-provider interface (SPI)**: a
`DataProviderInterface` (read) and a `DataPersisterInterface` (write), resolved per type by a
first-`supports()` match in descending priority order. The [Eloquent layer](eloquent.md) is
the reference implementation at priority `-128`; register your own at a higher priority to
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

## Priority and shadowing

Providers and persisters register with a priority; the first that `supports()` a type wins.
The reference Eloquent pair sits at `-128`, so an application registration (default `0`)
shadows it for its own types while everything else falls through to Eloquent:

```php
JsonApi::provider(new ChartProvider());                       // priority 0
JsonApi::provider($eloquent, priority: -128);                 // the fallback
```

This is how the example serves `charts`/`countries` from custom providers while every model
type falls through to Eloquent, and how a `libraries` polymorphic-to-many can be served by a
custom provider if you don't use the reference morph resolution.
