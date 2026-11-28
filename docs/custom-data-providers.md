# Custom data providers and persisters

Data access runs through a storage-agnostic **service-provider interface (SPI)**: a
`DataProviderInterface` (read) and a `DataPersisterInterface` (write), resolved per type by a
first-`supports()` match in descending priority order. The [Eloquent layer](eloquent.md) is
the reference implementation (auto-registered at `-256` from the
[model-map tiers](eloquent.md#the-model-map-three-tiers), or hand-wired at `-128`); register
your own at a higher priority to
serve a type from anything — an external API, a fixed list, `symfony/intl`, a legacy store.

## The read interface

```php
interface DataProviderInterface
{
    public function supports(string $type): bool;
    public function fetchOne(string $type, string $id): ?object;
    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult;

    // Relationship reads (defaulted in AbstractDataProvider — override only what you need):
    public function fetchRelatedCollection(/* … */): CollectionResult;
    public function fetchRelatedCollectionBatch(/* … */): RelatedBatch; // ?include batching
    public function countRelated(/* … */): array;                  // ?withCount
    public function relatedToOneMatches(/* … */): bool;
    public function relatedToOneMatchesBatch(/* … */): array;
    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array;
}
```

`fetchCollection()` receives a `CollectionCriteria` (the parsed filters, sorts, pagination
window) and returns a `CollectionResult` (the page of objects + total/`hasMore`). The
relationship seams power `?include` batching, `?withCount`, relationship-existence checks, and
pivots — the batcher orchestrates them provider-agnostically.

### Reading pivot rows

A `belongsToMany` with stored pivot fields renders each member's `meta.pivot` wherever its
linkage data renders, and the provider of its related type reads the stored values. Implement
`PivotAwareProviderInterface` on it, named and shaped as the Symfony bundle's:
`fetchRelatedPivotMap()` returns one parent's values and `fetchRelatedPivotMapBatch()` every
parent's at one include path, keyed by the parent's wire id and then the member's. Both take the
served parent type first, `$parentType, $relatedType`, so a provider serving one object as
several types can tell them apart.
`supportsPivot()` says whether it also queries the rows, filtering, sorting and paging a related
read by a pivot field through `fetchRelatedPivotCollection()`; answer `false` and the related
reads take the plain path, as the in-memory provider's do. A related read that returns a
`PivotCollectionResult`, from `fetchRelatedPivotCollection()` or from
`fetchRelatedCollectionBatch()` for an include, carries its members' values, so they render
from that page, and `fetchRelatedPivotMapBatch()` is asked only for the parents whose page
didn't carry them. A relation with stored pivot fields whose related type's provider doesn't
implement the interface fails the boot and `jsonapi:optimize`, naming the relation, the type,
the provider and the interface.

The Eloquent provider reads pivot rows over a parent model's `belongsToMany`. When a custom
provider serves the parent type and the Eloquent provider the related type, the first pivot read
throws a configuration error naming the relation and the parent's provider: serve the related
type from a provider that implements `PivotAwareProviderInterface` for those parents, answering
`supportsPivot()` false, or serve the parent type from the Eloquent provider.

`QueriesPivotColumnsInterface` and its `pivotMapOf()` are deprecated and go in the next major.
The package no longer consults them, so a provider that filtered or sorted by pivot columns
through them implements `supportsPivot()` and `fetchRelatedPivotCollection()` instead.

`fetchRelationshipPivot()` reads one parent's existing rows when a write arrives, and the
validator merges each incoming member's `meta.pivot` over the row it already has.

`supportsPivot()` also decides where a `->through()` path starting with `pivot` runs: its filters
and sorts reach `fetchRelatedPivotCollection()` beside the `pivot.`-columned ones. A path that goes on past
a pivot row, such as `pivot.addedBy`, needs the provider serving the relation's related type to
implement core's
[`PivotRowRelations`](https://github.com/haddowg/json-api/blob/main/src/Resource/Through/PivotRowRelations.php),
naming the relation each pivot row holds and the type it reaches; without it, route
registration refuses the path.

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

### Batching related reads

`fetchRelatedCollectionBatch()` loads one relation for a whole page of parents and returns a
`RelatedBatch` keyed by each parent's wire id. Give it an entry for every parent you loaded,
an empty `CollectionResult` for one with nothing related. A parent you leave out counts as not
loaded, never as empty. An `?include` then renders that parent's relation as the parent holds
it, and the Relationship Queries window and `?withCount` read it with
`fetchRelatedCollection()`. So the inherited default, an empty batch, is safe: your relations
render in full, one parent at a time. Override it only to save the queries.

A paginated to-many's include or rendered linkage is its
[first page](pagination.md#included-relationships-show-their-first-page): the criteria carry the
paginator's window, and the result's `total` or `hasMore` drives the page's links. A parent you
leave out of that batch is read with `fetchRelatedCollection()`, as the related endpoint reads
it, rather than rendered as the parent holds it.

The batcher never writes what it loads onto a parent object. An Eloquent model takes the
batched value as its loaded relation, exactly as its own lazy load would. Any other object is
left as it is, and the batched value renders from the request's own linkage. A filter in
`relatedQuery` that excludes a to-one target works the same way, so a record your provider
shares between requests is never changed by a read.

### Reading a relation through a foreign key

A relation declared with a foreign key
([relationships → relations read through a foreign key](relationships.md#relations-read-through-a-foreign-key))
is read by its related type's provider through
[`ReadsByForeignKeyInterface`](../src/DataProvider/ReadsByForeignKeyInterface.php), on every
path and whatever provider serves the parents. Implement it on a provider whose type is the
target of such a relation; route registration refuses one that doesn't.

```php
public function fetchByForeignKey(string $relatedType, RelationInterface $relation, ForeignKey $foreignKey, ForeignKeyParents $parents, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
{
    // $foreignKey->column is the member's storage name; $parents->keys maps each
    // parent's wire id to the key the column holds.
    $rows = $this->notes->whereIn($foreignKey->column, \array_values($parents->keys)); // one query
    // ...partition the rows by $parents->wireIdOf($row->{$foreignKey->column}), then cut
    // each partition to $criteria's window as fetchRelatedCollection() would.
}
```

`countByForeignKey()` counts each parent's rows over the criteria's filters, `0` for a parent
with none. A to-one's page holds its target as the first row. When the foreign key is a to-one
relation (`$foreignKey->isRelation()`), the column holds a reference to the parent, so compare
the id it references. The parents may come from any provider, so read their keys from
`ForeignKeyParents` rather than from your own storage. When it is a `MorphTo` identified by columns,
`$foreignKey->morphColumns()` returns its type and id fields: read the rows whose type field holds
one of `storedValues($parents->type)` and whose id field holds a parent's key, in either form.

A persister whose type is written through a foreign key implements
[`WritesForeignKeysInterface`](../src/DataPersister/WritesForeignKeysInterface.php):
`writeForeignKey()` points the joining rows at the parent and clears the key on the leaving
ones, and `requiresForeignKey()` says whether storage refuses a row with no key, which turns a
write that would clear one into a `403`. For a `MorphTo` identified by columns the type field is set
before `writeForeignKey()` is called, so it sets the id field, `$foreignKey->morphColumns()->id`.

A relation with no foreign key whose parent your provider serves, into a type the Eloquent or
in-memory provider serves, reads the members the parent model holds. Its pages and counts are
cut in memory from that value, unless your `fetchRelatedCollectionBatch()` or `countRelated()`
answers for it.

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

The provider, its store and the persister take the class of the rows as a template type, read
from the seeded rows or from the closures, so `identify` and `assignId` can type their item as
that class, such as `static fn(Genre $genre): string => $genre->slug`, and pass PHPStan. A
declaration that names the classes without the type, such as the return type
`InMemoryDataProvider`, reads as rows of any object, and `InMemoryDataProvider<Genre>` names
one class.

The store keeps each parent's pivot rows beside it. A relationship write, a create or update
carrying the relationship, and an atomic operation write the fields each member's `meta.pivot`
sends, and the provider reads them back through `PivotAwareProviderInterface`, so every response
renders them. To seed rows, write them to the store holding the parent with
`$provider->store()->writePivotRows($parent, $relationColumn, [$memberId => [$column => $value]])`.
It doesn't query those rows, so a filter, a sort or a path that reaches a pivot row needs a
store that does, such as the Eloquent provider. On a type the in-memory provider serves:

- a filter whose `->through()` path starts with `pivot` and ends on a pivot field answers `400`
  `FILTERING_UNRECOGNIZED`, as a `pivot.` column filter does, and so does a deprecated
  `pivot.`-prefixed traversal;
- a filter whose path reaches a pivot row mid-path, such as `tracks.playlists.pivot`, answers
  the same `400`, and a default declared on one answers it on every read of the endpoint;
- a path that goes on past `pivot`, such as `pivot.addedBy`, fails route registration and
  `jsonapi:optimize`, since the provider can't name the relations a pivot row holds.

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
applies a relationship endpoint write. Implement `TransactionalDataPersisterInterface`
(`beginTransaction()`, `commit()`, `rollback()`) and every write through the persister runs in
one transaction, from its first step and every Before* listener through the persister's write
(see [lifecycle](lifecycle.md#the-writes-transaction)); a writing
[action](actions.md#transactions) runs in one too, and an [atomic](atomic-operations.md) batch
runs all its writes in one. The Eloquent persister does this over its connection. A persister
that doesn't implement it writes without a transaction, and a batch that touches its type is
refused. `rollback()` has to undo everything since the matching `beginTransaction()`, including
a transaction a listener opened inside it and left open, and must be safe to call after a
failed `commit()`.

If your store has relations where a related row has one parent (a has-many over a foreign key
on the related row), implement `ReportsCurrentOwners`. Its `currentOwners()` returns the
incoming linkage members the parent doesn't hold yet, each with the parent that holds it now or
a null owner when none does. The handler then checks `update` on that parent (or the row), or
on the row alone when it has no parent, before the write moves it. Without it, rows move
unchecked.

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

Each of those reads first asks your provider's `fetchRelatedCollectionBatch()` for the whole
page, passing the parent's type and the page of parents, which are of a type your provider
doesn't serve. Key the result by each parent's wire id and give every parent you answer an
entry, an empty `CollectionResult` for one with nothing related. A parent you leave out is read
on its own with `fetchRelatedCollection()`, so the inherited empty batch keeps one read per
parent. An Eloquent global scope needs none of this: Eloquent applies it to every relation read
already.

A to-one to such a type loads the same way, and an answered batch is where its target comes
from: the first item of a parent's result, or no target when the result is empty. That holds on
every read of the relation, so `included`, linkage rendered without an include, the related and
relationship endpoints and a `relatedQuery` filter all show the batch's target. One parent is a
batch of one, and a page asks once however many of those render. The parent needs no relation
method, attribute or `extractUsing()` for it, so a read-only type your provider answers with one
grouped query per page can hang off an Eloquent model as is:

```php
HasOne::make('progress', 'progresses')->withData(),
```

```php
public function fetchRelatedCollectionBatch(string $parentType, array $parents, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): RelatedBatch
{
    $ids = array_map(fn (object $run): string => (string) $run->id, $parents);
    $rows = $this->progressByRun($ids); // one GROUP BY for the page

    return new RelatedBatch(array_combine($ids, array_map(
        fn (string $id): CollectionResult => new CollectionResult(isset($rows[$id]) ? [$rows[$id]] : []),
        $ids,
    )));
}
```

A relation computed with `extractUsing()` follows the same rule. The closure's value is only the
candidate set: when the related type has its own provider, the members load through that
provider's `fetchRelatedCollection()`, which narrows them before any filter, sort or page, so
linkage, `included`, both endpoints, `?withCount` and the window all show the same rows. On an
Eloquent type the closure's own queries already run through the model's global scopes, so a
closure that calls `withoutGlobalScopes()` gets exactly the rows it asked for.

A to-one target your provider's batch leaves out is read off the parent and kept only while the
provider's `fetchOne()` still returns it. Otherwise the relation renders as empty everywhere: linkage,
`included`, the relationship and related endpoints, a `MorphTo`, and any attribute flattened
through it with `on()`. Writing such an attribute through a hidden target is a `422`.

A polymorphic to-many that reaches such a type has no single provider to read through, so its
members are loaded off the parent as the candidates and narrowed per member type: for each
member type with its own scoping provider, the candidate ids of every parent are looked up in
one read through that provider, before any page is cut or count taken. Members of other types
are kept as loaded. Linkage, `included`, both endpoints and `?withCount` all show the narrowed
members. A provider that implements `FetchesManyInterface` answers that read with
`fetchMany($type, $ids)`; one that doesn't is asked `fetchOne()` once per id.

A `MorphToMany` declared with `identifiedBy()` only needs to know which of a parent's stored ids
your provider serves before it cuts a page or takes a count. A provider that can answer that
without loading the rows implements `AdmitsIdsInterface`, whose `admitIds($type, $ids)` returns
the wire ids it serves. Without it the ids are looked up through `fetchMany()`, or `fetchOne()`
once per id.

Writes go through the same provider. A persister looks related ids up in its own storage, so
before it applies a relationship write, each id the client names for such a type must come back
from the provider's `fetchOne()`. One that doesn't is a `404`, the same response as an id with
no row behind it, so a client can't tell the two apart. This covers create and update bodies,
`PATCH`/`POST`/`DELETE` on a relationship endpoint, pivot members with their `meta.pivot`, a
`MorphTo` or a polymorphic to-many (each id through its own member type's provider), and every
operation in an atomic batch, where the `404` rolls the whole batch back.
A to-many replace changes only the members the client can see: the persister is handed the
hidden current members as well, so they stay attached with their pivot rows untouched. The
response to a relationship write lists the members the relationship endpoint would.

## Filtered collections for actions

A [collection action that takes its type's filters](actions.md#a-collection-action-that-takes-the-types-filters)
reads its rows through the type's provider. Implement `QueriesFilteredCollectionInterface` to
serve them your own way:

- `queryFiltered($type, $criteria)` returns a fresh Eloquent builder with the criteria's filters
  applied, or `null` when your store has no builder;
- `eachFiltered($type, $criteria, $batch)` yields the filtered rows in ascending id order,
  reading `$batch` rows at a time.

The criteria carry only the filters the action takes, with no sorts and no window. Apply them
with `CriteriaApplier::applyFilters()`, which folds in the declared defaults and rejects an
undeclared key the same way a collection fetch does. Start from the query your
`fetchCollection()` starts from, so the action sees exactly the rows a client could list. The
Eloquent and in-memory providers both implement it.

A provider without the interface can't serve a filtered action: `filtered()` throws a
`LogicException` naming the type and the interface.

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

Match requested filter keys with core's `FilterMatcher` in the same way.
`FilterMatcher::directives($requested, $criteria->filters, $criteria->request)`, with the
declared defaults already folded into `$requested`, resolves [filter families](eloquent.md#filter-families),
throws `FilterParamUnrecognized` for an unknown key, and hands a [list filter](eloquent.md#list-filters)
its value as the list of members. A handler for a set filter reads members with
`$filter->list?->members($value)` so an array-only list keeps a comma inside a member.

Match requested sort keys with core's `SortMatcher` rather than comparing keys yourself, passing
the criteria's `$request`, so [sort families](eloquent.md#sort-families) resolve the same way on
every provider. `SortMatcher::directives($criteria->queryParameters->sort, $criteria->sorts, $criteria->request)`
returns the directives and throws the usual `400`s. A cursor provider passes the same request as
`KeysetResolver::resolve()`'s last argument and builds a backward page with `KeysetColumn::flipped()`,
which keeps a custom sort on its level.
