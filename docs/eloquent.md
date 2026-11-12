# The Eloquent data layer

The package ships a reference **Eloquent** `DataProvider` + `DataPersister` pair. Any resource
whose `$type` maps to a model reads and writes with no data code of your own — the
Laravel-native equivalent of the Symfony bundle's Doctrine layer. This page covers the
reference layer; the storage-agnostic SPI it implements is on
[custom-data-providers](custom-data-providers.md).

## The model map (three tiers)

Each type resolves to its model through three tiers — first match wins per type:

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

### Writes through `on()` attributes

An attribute flattened with `on('author')` or `on('publisher.country')` writes onto the related
model, not the parent. Core sets the value on the model the parent holds as a loaded relation,
and the persister saves that model in the parent's transaction, right after the parent's own
`save()`. A failure in either rolls back both, and inside an
[atomic operations](atomic-operations.md) batch the related save rolls back with the batch.

Only a related model with unsaved changes is saved. A multi-hop chain saves its last model and
skips the hops in between unless they changed too, and a write that sends the stored value
issues no `UPDATE`. The persister finds these models by walking the parent's loaded to-one
relations, so a loaded to-one model that a before-save hook changed is saved as well. It doesn't use `push()`, which would also save loaded to-many collections.

### Client ids and linkage ids

A create naming a client id that a row already has is a `409`
`CLIENT_GENERATED_ID_ALREADY_EXISTS`. The lookup ignores global scopes, so a soft-deleted or
scoped-out row still holds its id. A concurrent create can insert the id after the lookup, and
then the insert breaks the primary key. The persister answers the same `409` when the row
it failed to insert was never saved and a row with the id is there. If the database refuses that
second lookup, as PostgreSQL does in a failed transaction, the violation stays a `500`.

A linkage id sent to a related model with an integer key
must be the key's canonical form: `abc` or `01` names no row and is a `404`
`RESOURCE_NOT_FOUND`, the answer the Symfony bundle's Doctrine persister gives.

### Overriding one write

To change how one type's entities are built or deleted, extend `EloquentDataPersister` and
override `instantiate()` or `delete()`. The subclass keeps the inherited creates, updates,
relationship writes, transactions and soft-delete actions. Register it for its types at the
default priority, above the reference persister:

```php
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;

final class ArchivingNotePersister extends EloquentDataPersister
{
    public function __construct(private readonly Archiver $archiver)
    {
        parent::__construct(['notes' => Note::class]);
    }

    public function instantiate(string $type): object
    {
        return new Note(['status' => $this->archiver->initialStatus()]);
    }

    public function delete(string $type, object $entity): void
    {
        $this->archiver->archive($entity);
    }
}

JsonApi::persister(ArchivingNotePersister::class); // resolved from the container
```

A change that should apply to every write of the model, not only the API's, belongs on the
model instead: attribute defaults in `$attributes`, or a `deleting` observer that returns
`false` to stop the delete.

## Filters → query builder

Core's filter vocabulary is pushed down to the Eloquent builder. The example's resources
declare, and the provider translates:

| Filter | Becomes |
| --- | --- |
| `Where::make('slug')` | `where('slug', …)` (operator via the 3rd ctor arg, e.g. `'like'`, `'>'`) |
| `WhereIn::make('genres')` | `whereIn(...)` |
| `WhereIn::make('genres')->acceptsList()` | `whereIn(...)` over the members of `filter[genres][]=a&filter[genres][]=b` or `filter[genres]=a,b` |
| `Contains::make('title')` | `where('title', 'like', "%…%")` |
| `Range::make('rating', 'average_rating')` | a `>=`/`<=` bound pair |
| `DateRange::make('releasedAt', 'released_at')` | a datetime bound pair, in the app timezone (see [below](#date-times-and-the-app-timezone)) |
| `WhereHas::make('tracks')` | `whereHas('tracks')` (relationship existence) |
| `WhereThrough::make('artist.name')` | a dotted-path correlated `EXISTS` |
| `WhereThroughIn::make('label', 'artist.label')` | one correlated `EXISTS` over the path with a `whereIn` at the leaf |
| `WhereAll` / `WhereAny` | a nested `where(fn ($q) => …)` combining the children with `AND` / `orWhere` |
| `Where…->fixed($value)` | the same `where` predicate with its value pinned server-side (no arm needed) |

### List filters

`acceptsList()` on a set filter declares its value a list. The array form
`filter[genres][]=rock&filter[genres][]=jazz` always works, and so does the comma form
`filter[genres]=rock,jazz` unless you pass `delimited: false`. Use that when a member can contain
a comma: `filter[columns][]=last,first` asks for the one column named `last,first`, and a single
`filter[columns]=…` string is a `400 FILTER_VALUE_INVALID`. So is a member keyed by name, such
as `filter[genres][first]=rock`. Each member is validated on its own against the filter's
constraints, so `WhereIn::make('ids')->integer()->acceptsList()` rejects
`filter[ids][]=1&filter[ids][]=x`. Without `acceptsList()` a set filter reads both forms
leniently, as it always has.

### Filter families

Some filter keys are data, the filter twin of a [sort family](#sort-families). Core's
[`FilterFamily`](https://github.com/haddowg/json-api/blob/main/docs/filters.md#filter-families-keys-matched-by-prefix)
answers every `<prefix>.<suffix>` key and resolves the suffix, with the request, to an ordinary
filter keyed by the full key:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

public function filters(): array
{
    return [
        FilterFamily::make('attr', fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
            => \in_array($suffix, ['title', 'genre'], true) ? Where::make('attr.' . $suffix, $suffix)->build() : null)
            ->withSuffixPattern('[a-z]+'),
        FilterFamily::make('in', fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
            => \in_array($suffix, ['title', 'genre'], true) ? WhereIn::make('in.' . $suffix, $suffix)->acceptsList()->build() : null)
            ->acceptsList(),
    ];
}
```

`?filter[attr.genre]=jazz&filter[in.title][]=Kid A` then applies both resolved filters. The
rules are the sort family's: an exact key wins over a family, the longest matching prefix
decides between families, and the bare prefix (`filter[attr]`) or a suffix the resolver returns
`null` for is the usual `400 FILTERING_UNRECOGNIZED`. A family that takes a list must resolve to
filters that take the same list. A family key behaves exactly like the filter it resolves to.
That filter's constraints validate the value, so a family resolving to
`Where::make(…)->integer()` rejects a non-integer, and one resolving to a `singular()` filter
collapses the collection to one resource. On a relation, a family resolving to a
`pivot.`-columned filter compares the join table wherever an exact pivot filter does, and is
the same unrecognized `400` wherever an exact pivot filter is dropped.

Both providers match keys through core's `FilterMatcher`, on the primary collection, related
and relationship endpoints, a relation's own `filters()`, the Relationship Queries profile's
`relatedQuery[<path>][filter]`, relationship counts, cursor pages and a
[collection action's filters](actions.md#a-collection-action-that-takes-the-types-filters). A
family resolving to a built-in filter needs nothing more; one resolving to a custom filter needs
an [arm](#custom-filters-and-sorts-the-arm-seam) on each provider, as any custom filter does.
The OpenAPI `filter` parameter lists each family under `x-filter-families`, and a list filter
gets its own `filter[<key>][]` parameter.

### Filtering through a path: `WhereThroughIn` and relationship leaves

`WhereThroughIn` is core's [`WhereThrough` with a `WhereIn` at the
leaf](https://github.com/haddowg/json-api/blob/main/docs/filters.md#matching-a-list-through-a-path-wherethroughin).
Its value is always a list, so `filter[advertiser]=4,7` and
`filter[advertiser][]=4&filter[advertiser][]=7` both work, and each member is validated on its
own. The Eloquent provider runs it as one `whereHas` chain over the path with a `whereIn` on the
leaf, so the whole list is one correlated `EXISTS` whose leaf is an `IN`, never one `EXISTS` per
value. A path of two hops nests a second `EXISTS` inside the first, as `whereHas` does for any
dotted relation. An empty list matches nothing and adds `1 = 0` rather than an empty `IN`.

A `?withCount` count or a Relationship Queries total filtered by one of these counts correctly
too. Eloquent's `withCount` builds its subquery on the related model, so the `EXISTS` correlates
on each counted member rather than on the parent, and two traversals in one `WhereAll` or
`WhereAny` each correlate on their own.

A `WhereThrough` or `WhereThroughIn` path can also [end on a
relationship](https://github.com/haddowg/json-api/blob/main/docs/filters.md#paths-that-end-on-a-relationship):

```php
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;

public function filters(): array
{
    return [
        WhereThroughIn::make('advertiser', 'campaign.advertiser'), // filter[advertiser]=4,7
        WhereThroughIn::make('template', 'templates'),             // filter[template]=tpl-1,tpl-2
        WhereThrough::make('advertiserIs', 'campaign.advertiser'), // filter[advertiserIs]=4
    ];
}
```

The filter then compares the related model's key at the end of the `whereHas` chain, so a single
segment such as `templates` is a valid path. Both providers resolve each path after matching the
requested key, against the fields of the type the filters run against: the type's own on its
collection, and the related type's on a related or relationship endpoint, a relation's own
`filters()` included. A filter family's filter resolves the same way, per request. Each segment
matches a relation by the name its model method has, the relation's `storedAs()` column when it
declares one. A path whose segments are not all relations ends on a field and behaves as it
always did.

The client sends the ids it reads in `data.id`. Each one is decoded through the related type's
[id encoder](resources.md) before the query, and with no declared constraints the members
validate against the related id's format, so a `numeric()` id rejects `filter[advertiser]=4,x`
and a `uuid()` id anything that isn't a UUID. A bad member, or an id the encoder can't decode,
is a `400 FILTER_VALUE_INVALID` at `filter[<key>]` before the query runs. The
OpenAPI document types each list's items by the related id.

A path ending on a `MorphTo` or `MorphToMany` has no single id to compare. Route registration
and `jsonapi:optimize` resolve every declared filter, relation-scoped ones included, and fail
with the path that ends on one, as they do for a `WhereThrough` on a relationship that declares
`deserializeUsing()` or a non-equality operator.

### Date-times and the app timezone

Eloquent writes a `\DateTimeInterface` as `Y-m-d H:i:s`, both for a query binding and for a
datetime cast, so the offset is dropped. It reads the column back in PHP's default timezone,
normally `app.timezone`. The column therefore holds local time in that zone. A client bound such
as `filter[startsAt][min]=2026-10-01T15:00:00Z` is an instant, though, and comparing its
`15:00:00` against local times picks the wrong rows. Under `America/New_York` a row stored as
`2026-10-01 12:00:00`, which is 16:00Z, would be left out.

So a date-time compared against a column with a `datetime`, `immutable_datetime`,
`datetime:<format>` or `timestamp` cast, or one of the model's timestamp columns, is converted
to PHP's default timezone first. That covers `DateRange`, `Where` and its comparison presets when
their deserializer yields a date, and a `WhereThrough` leaf. Writes get the same treatment: a
`DateTime` attribute bound for such a column, sent as `2026-10-03T16:00:00Z`, is stored as
`2026-10-03 12:00:00` under New York rather than `16:00:00`, and so is a `DateTime` pivot field.
The timezone is read with `date_default_timezone_get()` each time, so an application that
switches it per request, say per tenant, gets the right zone.

A column with a `date` or `immutable_date` cast is compared with `whereDate()` against the
bound's own calendar day, never converted, because moving a midnight bound into another zone
can change its day: `2026-10-01T00:00:00Z` is still 30 September in New York. A column with a
custom cast class, or with no cast, is left alone, since its type is unknown. Cursor keys are not
converted either, because a cursor already carries the stored wall-clock time.

### Server-composed filter groups and `->fixed()`

`WhereAll` (AND) and `WhereAny` (OR) are core's [server-composed filter
groups](https://github.com/haddowg/json-api/blob/main/docs/filters.md#filter-groups-whereall--whereany):
value objects the resource author composes from child filters. The provider runs a group
by applying each child inside a nested `where(fn ($q) => …)` closure — combining with `AND`
(`WhereAll`) or `orWhere` (`WhereAny`) — and **fanning the group's request value uniformly
to every child**. So a fanning group is a multi-column search
(`WhereAny::make('q', Contains::make('name'), Contains::make('email'))` →
`filter[q]=foo` compiles `... and (lower(name) like ? or lower(email) like ?)`), while a
group of [`->fixed()`](https://github.com/haddowg/json-api/blob/main/docs/filters.md#fixed-values)
children is a canned toggle whose request value is ignored. Groups **nest** — a group child
re-enters the same dispatch — so `(A AND (B OR C))` compiles to the matching nested `where`
closures.

`->fixed($value)` pins a `Where`'s compared value: `filter[<key>]` present with any value
applies `column = <literal>` and its value is ignored. It needs no arm of its own — the
pinned value rides core's existing value-deserializer seam, so the `Where` push-down above
runs it unchanged.

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

### Custom sorts on a cursor page

A cursor page builds its own `ORDER BY` and keyset `WHERE`, so it never calls a sort arm's
`apply()`. A custom sort only orders cursor pages when both halves opt in. The sort implements
core's [`KeysetSortInterface`](https://github.com/haddowg/json-api/blob/main/src/Resource/Sort/KeysetSortInterface.php),
whose `keysetValue($row)` gives the value the cursor token stores for a boundary row. Its arm
implements [`EloquentKeysetSortArmInterface`](https://github.com/haddowg/json-api-laravel/blob/main/src/DataProvider/Eloquent/EloquentKeysetSortArmInterface.php),
which adds one method to `EloquentSortArmInterface`:

```php
public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression;
```

The provider calls it once per query and uses the expression wherever it would use a column.
It orders by `CASE WHEN (expr) IS NULL THEN 1 ELSE 0 END` and then the expression, so null sorts
as the largest value, and compares the expression with the token's boundary value in the same
four null-aware cases a column gets. The arm may add joins to `$query`. When it does and the
query selects nothing yet, the provider selects the model's own columns, so a joined `id` can't
overwrite the model's. Use a left join, so rows without a value stay in the page and sort as
null. A cursor page ordered by a custom sort without both opt-ins is a `500` `UnsupportedSort`.

`EloquentKeysetExpression::make($sql, $bindings)` takes raw SQL (a string or an `Expression`)
and the values for its own `?` placeholders. The boundary value arrives in its token form, a
number or string as written and a date-time as an RFC 3339 string with microseconds. How it is
bound depends on the expression:

| Method | The boundary binds as |
|---|---|
| none | the token value as-is, for a number or string |
| `asDateTime()` | a `DateTimeImmutable` in the offset the token carries |
| `asNaiveDateTime()` | a `DateTimeImmutable` moved into PHP's default timezone, for a datetime column Eloquent stores without its offset (see [date-times and the app timezone](#date-times-and-the-app-timezone)) |
| `castUsing(fn)` | whatever the callable returns for the token value |

A string that doesn't parse as a date binds verbatim. Nothing converts a custom expression's
date-time to another timezone unless the arm asks for `asNaiveDateTime()`. The expression and
`keysetValue()` must agree on every row, nulls included, or pages skip or repeat rows.

Here a campaign's placeholder values live in a `campaign_values` table with a text `value`
column. A [sort family](#sort-families) resolves `value.<name>` to a `PlaceholderValueSort`,
and one arm orders page and cursor pages by joining the placeholder's row and casting it:

```php
use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;

final readonly class PlaceholderValueSort implements KeysetSortInterface
{
    public function __construct(public string $key, public string $placeholder) {}

    public function key(): string
    {
        return $this->key;
    }

    public function keysetValue(mixed $row): mixed
    {
        // Runs only for a page's first and last rows.
        $value = $row->values->firstWhere('name', $this->placeholder)?->value;

        return \is_numeric($value) ? (float) $value : null;
    }
}
```

```php
use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetExpression;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetSortArmInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

final class PlaceholderValueArm implements EloquentKeysetSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof PlaceholderValueSort;
    }

    public function apply(SortInterface $sort, Builder $query, bool $descending): void
    {
        $query->orderBy(new Expression($this->join($sort, $query)), $descending ? 'desc' : 'asc');
    }

    public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression
    {
        return EloquentKeysetExpression::make($this->join($sort, $query));
    }

    private function join(PlaceholderValueSort $sort, Builder $query): string
    {
        // The placeholder names the alias, so only a plain identifier reaches the SQL.
        if (\preg_match('/^[a-z_]+$/', $sort->placeholder) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a placeholder name.', $sort->placeholder));
        }

        $alias = 'sort_value_' . $sort->placeholder;
        $model = $query->getModel();

        if ($query->getQuery()->columns === null) {
            $query->select($model->qualifyColumn('*'));
        }

        $query->leftJoin('campaign_values as ' . $alias, function (JoinClause $join) use ($alias, $model, $sort): void {
            $join->on($alias . '.campaign_id', '=', $model->getQualifiedKeyName())
                ->where($alias . '.name', '=', $sort->placeholder);
        });

        return 'CAST(' . $alias . '.value AS REAL)';
    }
}
```

A date placeholder stored as `Y-m-d H:i:s` text would return a `DateTimeImmutable` from
`keysetValue()` and `->asDateTime()` from `keysetExpression()`, so the boundary binds back to
the same text form. Register the arm like any other sort arm. The in-memory provider needs
only an `ArraySortArmInterface` for page ordering, since its cursor pages read `keysetValue()`.
The workbench's `sortCampaigns` type runs this example on both providers.

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

> **Describe a custom filter in the OpenAPI document.** A custom `FilterInterface` with no
> value constraints projects a plain `string` `filter[…]` parameter with a generic
> description — accurate, but nothing a reader learns from. Two opt-in core interfaces fix
> that, and a custom filter gets neither by accident:
>
> - [`DescribedFilter`](https://github.com/haddowg/json-api/blob/main/src/Resource/Filter/DescribedFilter.php)
>   (`getDescription(): ?string`) gives the parameter its own prose — the same hook the
>   convenience filter library uses.
> - [`DescribesQueryParameter`](https://github.com/haddowg/json-api/blob/main/src/Resource/Filter/DescribesQueryParameter.php)
>   declares the parameter envelope: the value schema plus the OAS `style`/`explode` for a
>   **structured** wire shape, so a nested object or comma-list documents as a `deepObject`
>   or array rather than a scalar. A *scalar* custom filter wants it whenever its value is
>   narrower than core can work out — core types an unconstrained filter's value from the
>   single column it targets, and a filter targeting several columns (or none) falls back to
>   the `string` a query parameter is on the wire. The example's
>   [`FullTextSearch`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/Query/FullTextSearch.php)
>   searches several columns and declares that `string` itself, which is what the fallback
>   would have given it — the declaration starts earning its keep the moment the wire shape
>   stops being a plain scalar.
>
> (Built-in and `Where`/`Range`-derived filters already self-describe.)

## Soft deletes

A resource whose model uses Laravel's `SoftDeletes` trait can opt into first-class soft
deletes with `#[AsJsonApiResource(softDeletes: true)]` — `DELETE` becomes a recoverable soft
delete and the package synthesizes `restore`/`force-delete` actions. See
[soft deletes](soft-deletes.md).

## UUID keys

A string UUID key works with no extra wiring. Use Laravel's `HasUuids` trait on the model and
declare `Id::make()->uuid()` on the resource; the canonical UUID the column holds is the wire id.

A UUID stored as 16 raw bytes, such as a `BINARY(16)` column, keeps the bytes on the model and
attaches the package's `BinaryUuidEncoder` to the id:

```php
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;

Id::make()->uuid()->encodeUsing(new BinaryUuidEncoder());
```

The wire then carries the canonical UUID, and the package decodes it back to the bytes for
`fetchOne`, linkage writes, client-supplied ids and every relation batch. Foreign keys hold the
bytes too, so Eloquent's relations match them as they are. To mint the key, override
`newUniqueId()` to return `Str::orderedUuid()->getBytes()`.

Don't cast a key column with `AsBinary::uuid()`. Eloquent binds the cast string, not the
bytes, when it queries by key or loads a relation, so `find()`, eager loading and pivot writes
miss every row.

## Sorting, sparse fieldsets, pagination

`sortable()` fields drive `?sort` (`SortByField::make($key, $column)` maps a wire key to a
column); `defaultSort()` orders an unsorted collection. Sparse fieldsets narrow the selected
columns. Page / offset / cursor (keyset) pagination all push down — see
[pagination](pagination.md).

A to-many's members read in the relation's own `defaultSort()`, else the related type's, on
every path, [include batching](#include-batching--spi-not-with) and
[windows](#windowed-relationship-queries--sql-push-down-only) included. An `orderBy` on the
Eloquent relation method gives way to a requested sort or the relation's own default, and
leads the related type's default. A pivot key orders by the join table's column.

### Sort families

Some sort keys are data: a campaign's placeholder values, custom fields, keys of a JSON column.
Core's [`SortFamily`](https://github.com/haddowg/json-api/blob/main/docs/sorts.md#sort-families-keys-matched-by-prefix)
answers every `<prefix>.<suffix>` key and resolves the suffix, with the request, to an ordinary
sort keyed by the full key:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;

public function sorts(): array
{
    return [
        SortFamily::make('attr', fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface
            => \in_array($suffix, ['name', 'team'], true) ? SortByField::make('attr.' . $suffix, $suffix) : null)
            ->withSuffixPattern('[a-z]+'),
        SortFamily::make('value', fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface
            => $this->placeholders->exists($suffix) ? new PlaceholderValueSort('value.' . $suffix, $suffix) : null),
    ];
}
```

`?sort=team,-value.cap` then sorts by the fixed `team` key and the `cap` placeholder. An exact
key wins over a family, the longest matching prefix decides between families, and the bare
prefix (`?sort=value`) or a suffix the resolver returns `null` for is the usual `400`. Both
providers match keys through core's `SortMatcher`, on the primary collection, related and
relationship endpoints, a relation's own `sorts()`, the Relationship Queries profile's
`relatedQuery[<path>][sort]` and `defaultSort()`. A family resolving to a `SortByField` needs
nothing more. One resolving to a custom sort needs an
[arm](#custom-filters-and-sorts-the-arm-seam) on each provider, and a
[keyset arm](#custom-sorts-on-a-cursor-page) to order cursor pages. The OpenAPI `sort`
parameter lists each family under `x-sort-families` beside the fixed keys.

## `?include` batching — SPI, not `with()`

Compound documents are assembled by a provider-agnostic **batcher** over the SPI, not by
top-level Eloquent `with()`:
top-level `with()` cannot return the per-parent totals / `hasMore` a windowed relationship
needs. The Eloquent provider *implements* the batch seams using Eloquent's own eager-load
internals (`addEagerConstraints` + `getEager` + dictionary matching, a `BelongsTo`
FK-projection for the to-one arm), then writes the result back with `$parent->setRelation()`
— so `relationLoaded()` is true and the load-state seam renders linkage without a second
fetch.

## Windowed relationship queries — SQL push-down only

Per-parent relationship paging (the Relationship Queries profile, `?withCount`, a related
collection with its own `page[]`) is a **SQL push-down only** — no toggle, no PHP fallback.
It uses Eloquent's relation `limit()` → `Builder::groupLimit()` →
`ROW_NUMBER() OVER (PARTITION BY …)` with the relation's order plus a deterministic id
tie-breaker; `hasMore` is an N+1 probe on the same query; countable totals are a grouped
`COUNT`. Every first-party Laravel driver has window functions, so the Doctrine toggle's
portability rationale does not transfer. The in-memory witness runs core's `WindowExecutor`
with the same id tiebreak, so the conformance suite referees SQL vs PHP windowing on every
run.

## Polymorphic relations and the morph map

The Eloquent morph **alias** (the `Relation::morphMap()` value) is decoupled from the JSON:API
**type**:
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
