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
Each of those nests as a savepoint in the transaction the package opens around the whole write
through the persister's `beginTransaction()`, so a row a Before* listener saved rolls back with
it (see [lifecycle](lifecycle.md#the-writes-transaction)). `rollback()` returns the connection
to the level it began at, closing any transaction a listener opened inside it.

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
second lookup, as PostgreSQL does in a failed transaction, the violation stays a `500`. Inside
the write's transaction the insert runs in a savepoint, which Laravel rolls back to when it
fails, so PostgreSQL still answers the lookup.

A before hook may save the new model itself, for example to read its generated key. The
model then exists, so the persister saves it as usual rather than reading its own row as a
taken id. A client id another row holds is still refused, but the hook's `save()` meets it
first, as the database's unique violation, a `500`, since the persister checks the id only
after the hooks have run. The in-memory persister likewise accepts the entity a hook already
saved.

A linkage id sent to a related model with an integer key
must be the key's canonical form: `abc` or `01` names no row and is a `404`
`RESOURCE_NOT_FOUND`, the answer the Symfony bundle's Doctrine persister gives. An id no row
holds never reaches the persister: the handler checks every linkage id against the related
type's provider first and answers the same `404`.

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
| any of these with `->through('campaign.advertiser')` | one nested `whereHas` per relation of the path, with the filter's own translation on the innermost (see [below](#filters-reaching-through-relations-through)) |
| `WhereThrough::make('artist.name')` (deprecated) | a dotted-path correlated `EXISTS` |
| `WhereThroughIn::make('label', 'artist.label')` (deprecated) | one correlated `EXISTS` over the path with a `whereIn` at the leaf |
| `WhereSet::make('archived', 'archived_at')` | `whereNotNull` for `true`, `whereNull` for `false`, nothing for both |
| `WhereMorph::make('subject')` on a `MorphTo` | `whereIn` on the model's `morphTo` type column, the alias the morph map gives the requested type's model, and on its id column when ids are sent, each id as its storage key and its wire id |
| `WhereMorph::make('linked', 'links')` on a `MorphToMany` | one `EXISTS` over the `through()` rows `identifiedByRows()` names, owned by the outer row |
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

A `WhereIn` or `WhereNotIn` declaring `deserializeUsing()` hands the validated members to its
closure in one call and runs `whereIn` or `whereNotIn` over the list it returns, so the
constraints and `enum()` apply to the values a client sends. An empty returned list matches
nothing for `WhereIn` and excludes nothing for `WhereNotIn`, as an empty requested list does.
See [Mapping a requested list](https://github.com/haddowg/json-api/blob/main/docs/filters.md#mapping-a-requested-list-deserializeusing).

On PostgreSQL, which refuses to compare an integer or uuid column with a string of another
kind, a filter value such a column can't hold matches no row: an equality or `IN` member drops
out, and an inequality keeps every row with a value. The column's type is read from the schema
once per column. Other databases compare such a value and find no row anyway.

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
the same unrecognized `400` wherever an exact pivot filter is dropped. Route registration
can't see the paths a family resolves, so a `pivot.`-prefixed path a family returns that
reaches a model no single registered type serves is refused when the request resolves it.
The request answers `500` and the error log names the family, the filter and the path, since
the declaration is at fault rather than the request.

Both providers match keys through core's `FilterMatcher`, on the primary collection, related
and relationship endpoints, a relation's own `filters()`, the Relationship Queries profile's
`relatedQuery[<path>][filter]`, relationship counts, cursor pages and a
[collection action's filters](actions.md#a-collection-action-that-takes-the-types-filters). A
family resolving to a built-in filter needs nothing more; one resolving to a custom filter needs
an [arm](#custom-filters-and-sorts-the-arm-seam) on each provider, as any custom filter does.
The OpenAPI `filter` parameter lists each family under `x-filter-families`, and a list filter
gets its own `filter[<key>][]` parameter.

### Filters reaching through relations: `->through()` {#filters-reaching-through-relations-through}

A filter declared with [`->through()`](https://github.com/haddowg/json-api/blob/main/docs/filters.md#reaching-through-relations-with-through)
reads a field of the type its path of relations reaches. The provider nests one `whereHas` per
relation of the path, each on the relation method its `storedAs()` names, and runs the
filter's own translation from the table above on the innermost query, against the column the
reached field stores. For `campaign.advertiser` and a `Where` on the advertiser's name:

    WHERE EXISTS (SELECT * FROM campaigns WHERE campaigns.id = leads.campaign_id
      AND EXISTS (SELECT * FROM advertisers WHERE advertisers.id = campaigns.advertiser_id
        AND advertisers.name = ?))

Each `whereHas` applies the related model's global scopes, so a row a scope hides matches no
filter. A to-many hop never multiplies rows. A relation that holds nothing gives the `EXISTS`
no row, so it never matches, `WhereNull` and `WhereSet` sent `false` included; an existence
filter at the end of a path, such as
`WhereDoesntHave::make('unadvertised', 'advertiser')->through('campaign')`, is how to find
empty relations, and it counts only the rows the scope admits. Core decodes a wire id the leaf
compares once, through the reached type's encoder, so the leaf doesn't decode it again.

`jsonapi:optimize` refuses a path the provider can't run, naming the type, the server and the
filter: a segment that is no relation method of the model it starts from, a hop into a type no
Eloquent model serves or that a provider other than Eloquent scopes, since a `whereHas` applies
only the related model's global scopes, and a leaf that is no column of the reached model's
table.

#### Paths through a pivot row

A `pivot` segment in the path
([reaching a pivot row](https://github.com/haddowg/json-api/blob/main/docs/filters.md#reaching-a-pivot-row))
reaches the pivot rows of a `belongsToMany` with stored pivot fields. A path that starts with
`pivot` reads the pivot row of the relation it runs under, which the relation's query has
joined, so it applies wherever the `pivot.` column filters do: the related and relationship
endpoints, `relatedQuery[<relation>][filter]` and its counts. Ending there, the filter compares
the join table's column the pivot field stores, cast as the pivot model casts it. Elsewhere,
such as an included to-many's linkage read with no Relationship Queries filter, it is the
unrecognised `400` a `pivot.` column filter is.

```php
BelongsToMany::make('playlists', 'playlists')
    ->fields(Integer::make('position'), DateTime::make('addedAt')->storedAs('added_at')->readOnly())
    ->withFilters(
        DateRange::make('addedAt', 'addedAt')->through('pivot'),       // the join table's added_at
        Where::make('addedBy', 'name')->through('pivot.addedBy'),       // a relation of the pivot model
        WhereDoesntHave::make('unaudited', 'auditor')->through('pivot'), // no auditor on this row
    );
```

After `pivot`, a segment names a relation method of the pivot model the `belongsToMany` names
with `->using()`, such as `addedBy()`. The provider reads the type it reaches from the model that
relation targets: the one type it serves with that model, or for the pivot model's relations
back to the parent and to the member, the parent type and the relation's related type. A
`morphTo` on the pivot model, a model no type serves and a model several types serve all fail
route registration, since the reached type's scope, read gate and id encoder have to apply. A
deprecated `pivot.`-prefixed `WhereThrough`, `WhereThroughIn`, `WhereHas` or `WhereDoesntHave`
going on from the pivot row is refused the same way. From there the path goes on as any
other, one `whereHas` per relation. The first one is built from the pivot model the way
Eloquent builds a `whereHas`, so it correlates to the join row the query already holds.

A `pivot` in the middle of a path, as in `tracks.playlists.pivot.addedBy`, is one more `EXISTS`
over the join table: the rows linking the current row to each member, with the member itself
kept to the rows its model's global scopes admit, so a link to a hidden member never matches.
The rest of the path goes on from the pivot row.

`jsonapi:optimize` refuses a `pivot` after any relation the provider reads no pivot rows of: one
that isn't a `belongsToMany` with stored pivot fields, a `morphToMany` included, since its link
rows aren't walked by a path yet. It also refuses a mid-path `pivot` whose members a provider
other than Eloquent scopes, and a leaf after `pivot` that is no column of the join table.

A sort takes only a leading `pivot`. Ending there, it orders by the join table's column; going
on, the first correlated subselect is the pivot model's relation from the joined row. On a
cursor page the level reads the join table's column, or the subselect, from the query row,
whichever query the relation joins it on, so a windowed include orders and mints its tokens as
the related endpoint does.

### Filtering through a path: `WhereThroughIn` and relationship leaves

`WhereThrough` and `WhereThroughIn` are deprecated in favour of
[`->through()`](#filters-reaching-through-relations-through) and are removed in the next major
version. They keep the behaviour described here until then, and they don't reach through a
relation [read through a foreign key](relationships.md#relations-read-through-a-foreign-key),
which `->through()` does. A path that starts at the
[pivot row](relationships.md#pivot-belongstomany-data) moves to a leading `pivot` segment, see
[paths through a pivot row](#paths-through-a-pivot-row).

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

A path may also end on a `belongsTo` of a model that isn't a resource, such as a pivot model's
`definition`: `WhereThroughIn::make('definition', 'placeholderRows.definition')` walks the
template's rows and compares each row's foreign key. Each value goes through the id encoder of
the type the related model serves, so a canonical UUID matches a binary key. When several types
serve that model with different encoders, a type the declaration names settles it: the type
the filters run against, the related types of its relations, or on a relation's filters the
type declaring the relation. When none of those serves the model, route registration and
`jsonapi:optimize` refuse the filter, naming it and the candidate types. Types that share an
encoder, or have none, need no naming. A path naming the
raw foreign-key column, `placeholderRows.definition_id`, compares the same way, as long as the
model declares the `belongsTo` with a `BelongsTo` return type so it can be found. Any other
column is compared as sent, since Eloquent knows nothing of its stored form.

The id filters decode the model's own key too: `WhereIdIn`, `WhereIdNotIn` and a `Where` on
the key decode each value through the encoder of the type the filter runs against, the related
type on a relation's endpoints and windows, and compare a value the encoder doesn't recognise
as sent. Two types sharing one model each decode with their own encoder.

A path ending on a `MorphTo` or `MorphToMany` has no single id to compare. Route registration
and `jsonapi:optimize` resolve every declared filter, relation-scoped ones included, and fail
with the path that ends on one, as they do for a `WhereThrough` on a relationship that declares
`deserializeUsing()` or a non-equality operator.

### Whether a column is set, and polymorphic identifiers

[`WhereSet`](https://github.com/haddowg/json-api/blob/main/docs/filters.md#whether-a-column-is-set-whereset)
answers `filter[archived]=true` with `whereNotNull` and `false` with `whereNull`, so read or
archived state kept as a nullable timestamp filters by its key. Its value is always a list of
booleans, so `filter[archived]=true,false` asks for both, which is how a client overrides a
`default(false)`. A member that isn't a boolean is a `400` `FILTER_VALUE_INVALID`.

[`WhereMorph`](https://github.com/haddowg/json-api/blob/main/docs/filters.md#filtering-by-a-polymorphic-relation-wheremorph)
compares what a polymorphic relation stores, sent as `filter[subject][type]=rfps` with an
optional `filter[subject][id]=812,813`. On a `MorphTo` it reads the model's own `morphTo`
relation of the same storage name: the type column must hold the morph class of the model
serving the requested type, which is its morph map alias when the map names one, and the id
column one of the decoded ids. The relation's identifier needs no declaration, so a `MorphTo`
identified by an `identifiedBy()` closure filters too. A model without a `morphTo` of that name
falls back to the columns core's `identifiedByColumns()` names, through its `typeMap`. On a
`MorphToMany` the filter needs `identifiedByRows()`, and is one `EXISTS` over the `through()`
rows owned by the outer row, through the owner's `belongsTo` or `morphTo` on the row model.
When the model serving the requested type has global scopes, either form also requires the
target to exist under them, so a target they hide never matches; see
[filters that reach through relations](authorization.md#filters-that-reach-through-relations).
A key with no value-object cast and no id encoder, of the same family as the morph column,
both integers or both text, is kept to a subquery of the scoped keys, with a string key read
as text on PostgreSQL so a native uuid column compares. A binary UUID key behind
`BinaryUuidEncoder` keeps the subquery too, with the key read as its canonical lowercase text
inside it: `BIN_TO_UUID()` on MySQL 8, a `LOWER(HEX())` expression with dashes on MariaDB,
older MySQL and SQLite, and an `ENCODE()` expression on PostgreSQL. On a database whose text
column can hold raw bytes the subquery also compares the key as stored, so a column holding
the 16-byte storage key, which `associate()` writes, still matches. Any other key is admitted
the way a related read finds its target: an integer key under a text column, which PostgreSQL
can't compare, a key behind another cast, an encoded id, or a database with none of those
expressions. The requested ids, or with none the distinct ids of that type the column holds,
are decoded through the encoder or set through the key's cast and looked up under the scopes
in one query, and the ids found are bound in lists of 2000 joined with `OR`, so a long list
stays under the database's parameter limit. On PostgreSQL the id column's own type decides what it
compares with, read from the schema once per column: an integer column binds only integer
ids, and a native uuid column binds only UUIDs and compares with a uuid key directly, while a
key of any other type is admitted and bound. A UUID the column holds in capitals matches the
lowercase key, and renders as its lowercase wire id in a related read, so a reference written by
another system in either case still names its target.

The id column may hold the target's storage key or its wire id. They differ only when the
target's type declares an id encoder, and a text column that holds the ids of several kinds of
target often keeps the wire form, while Eloquent's own `associate()` writes the storage key.
Each requested id is compared in both forms, and the linkage, the related endpoint and the
include read either. A link row of a `MorphToMany` works the same way. A column the model casts
to `int` is only compared with the numeric forms.

The in-memory provider compares the fields `identifiedByColumns()` or `identifiedByRows()`
name, through core's reference handler, so a type it serves needs one of them. Route
registration and `jsonapi:optimize` refuse a `WhereMorph` whose provider can't compare the
relation, naming `identifiedByColumns()`.

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
switches it per request, say per tenant, gets the right zone. Only a `DateTime` documented as an
RFC 3339 `date-time` is converted on write, since the moved value is written back in that shape.
A field with a custom `format()` is hydrated as sent, so if that format carries an offset, such
as `D, d M Y H:i:s O`, its column stores the wall clock of the offset the client wrote.

A column with a `date` or `immutable_date` cast is compared with `whereDate()` against the
bound's own calendar day, never converted, because moving a midnight bound into another zone
can change its day. A `DateRange` over a column a `Date` field backs takes a full date for each
bound, `filter[day][min]=2026-10-01`, documented as `format: date`, and the in-memory provider
compares the same calendar day. A column with a
custom cast class, or with no cast, is left alone, since its type is unknown. Cursor keys are not
converted either, because a cursor already carries the stored wall-clock time.

A `Where` comparing with an equality or ordering operator, a `WhereIn` and a `WhereNotIn` over
a `DateTime`, `Date` or `Time` field, on the type or reached with `->through()`, read each value
as that field's date unless they declare a deserializer of their own. A date-time compares as an
instant, converted as above, a date compares its calendar day with `whereDate()` and a time its
time of day with `whereTime()`, each member of a list on its own. The in-memory provider compares
the same day, time of day or instant, so both stores match the same rows.

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
null. The provider wraps the expression in parentheses, so a `CASE`, a `COALESCE` or a
subquery such as `select max(score) from members where members.team_id = teams.id` works as
written.

A collection that may page by cursor has its declared sorts checked by `jsonapi:optimize`. A
custom sort that does not implement `KeysetSortInterface`, or whose arm does not implement
`EloquentKeysetSortArmInterface`, fails the deploy with a message naming the type, the sort and
the fix. That covers the type's own collection and every to-many whose related collection pages
by cursor. A [sort family](#sort-families) resolves its sorts per request, so a family that
resolves to such a sort is still a `500` `UnsupportedSort` when a request uses it.

A to-many's first page that renders with its parent, through `?include` or `withData()`,
orders by a custom sort too. A cursor-paged relation cuts it with the sort's expression,
minting each parent's cursor as the relation's endpoint does, and a numbered page orders
it through the arm's `apply()`. The relationship's next link pages on through the
relation's endpoint.

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

`valueFromQuery()` takes `keysetValue()` out of that agreement on Eloquent. The provider
selects the expression beside the model's columns, writes the boundary row's selected value
into the token, and drops the selected value from the models before they render. Use it for a
value PHP can't compute from the model without loading more, such as a count from a subquery.
With `asDateTime()` or `asNaiveDateTime()` a selected string is read in PHP's default timezone,
the zone the connection wrote it in. `keysetValue()` still orders the sort on the in-memory
provider.

```php
public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression
{
    return EloquentKeysetExpression::make(
        'select max(members.score) from members where members.team_id = teams.id',
    )->valueFromQuery();
}
```

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

On PostgreSQL use a native `uuid` column with a string UUID key instead. Laravel binds a string
as text, which a `bytea` column refuses when it holds bytes that aren't valid UTF-8, and PDO reads
a `bytea` value back as a stream, so Eloquent can neither save nor match a 16-byte key there.

Don't cast a key column with `AsBinary::uuid()`. Eloquent binds the cast string, not the
bytes, when it queries by key or loads a relation, so `find()`, eager loading and pivot writes
miss every row.

## Keys behind casts

A key or foreign key column may sit behind a value-object cast, a class implementing
`CastsAttributes` or `CastsInboundAttributes`, so the model reads an object while the database
holds what the cast's `set()` returns. The provider and persister bind and compare the stored
value, never the object or its string: fetching by id, admitting ids, foreign-key reads and
counts, to-many windows and cursor pages, link rows, linkage writes, pivot writes and the
unique-row rule. A wire id with no id encoder is set through the key's cast before it is bound.
A cast to an object whose string is the stored text, such as a value object over a text key,
works everywhere, Eloquent's own relations included. A cast that stores something else, such
as a UUID object over 16 bytes, works on a field named as a foreign key, which the package reads
itself, but not on a key Eloquent's own relations load, for the reason above.

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

A `SortByField` declared with [`->through()`](https://github.com/haddowg/json-api/blob/main/docs/sorts.md#sorting-by-a-related-field-through)
orders by a correlated subselect per to-one relation of its path. Each is the relation's own
existence query over the related model, so its global scopes apply, the innermost selects the
leaf column and each outer one the next, one row each. A relation that holds nothing and a row
a scope hides both leave the subselect empty, so the row sorts as a null column does: by the
database's own order on an offset page, and as the largest value on a cursor page. On a cursor
page the level selects the subselect beside the model's columns and the cursor reads its
boundary value from that row, so the token and the keyset `WHERE` agree on scoped rows. Route
registration and `jsonapi:optimize` resolve every declared sort, its type's default order and
each relation's, so a path through a to-many fails the boot, and `jsonapi:optimize` refuses a hop
whose relation method isn't a `belongsTo`, `hasOne`, `morphOne` or `hasOneThrough`.

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

A paginated to-many renders its
[first page](pagination.md#included-relationships-show-their-first-page) instead, read for the
whole level in one bounded query per relation (see the next section) and handed to the
document beside the model, never written onto it. The write-back above describes a relation
that keeps its whole set: one declared `withoutPagination()`, or one exposing no endpoint.

## Windowed relationship queries — SQL push-down only

Per-parent relationship paging (the Relationship Queries profile, `?withCount`, a related
collection with its own `page[]`) is a **SQL push-down only** — no toggle, no PHP fallback.
It uses Eloquent's relation `limit()` → `Builder::groupLimit()` →
`ROW_NUMBER() OVER (PARTITION BY …)` with the relation's order plus a deterministic id
tie-breaker; `hasMore` is an N+1 probe on the same query; countable totals are a grouped
`COUNT`.

Every rendered to-many's first page takes the same path: one bounded query per relation for
the whole page of parents, at every level of a nested include. The related model's global
scopes run inside that query, so a row a scope hides never takes a place on the page. A
relation with no default order is cut in the order its relation method's `orderBy` gives it,
the order the related endpoint serves. Relations over one relation method read with the same
page, order and count share one query, and a first cursor page, a `belongsToMany`'s included,
is the same window ordered by the keyset. A `?withCount` total a counted first page already
holds is taken from the page rather than counted again. Every first-party Laravel driver has window functions, so the Doctrine toggle's
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
> Model events fire **mid transaction** (on each `save()`), in a single write's transaction or
> an [atomic operations](atomic-operations.md) batch's, while the JSON:API After* events fire
> after the commit. Keep side effects that must not fire on a rolled-back operation in an
> After* hook.
