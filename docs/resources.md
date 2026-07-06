# Resources

A JSON:API type is a class extending core's `AbstractResource`. It declares the wire `$type`,
the `fields()` inventory (attributes + relations + the id), and optionally `filters()` /
`sorts()`. Discovery finds it; the `#[AsJsonApiResource]` attribute carries the extras. This
page covers the Laravel affordances around a resource; the field/relation/constraint DSL
itself is core's — see core
[fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md),
[relations](https://github.com/haddowg/json-api/blob/main/docs/relations.md), and
[constraints](https://github.com/haddowg/json-api/blob/main/docs/constraints.md).

## Discovery

Any class extending `AbstractResource` under a scanned path (default `app/JsonApi/`) is
discovered — no registration. The scan reads `::$type` statically and constructs the resource
lazily through the container on first use, so a resource may take constructor dependencies.

Escape hatches, called from a service provider's `register()`:

```php
use haddowg\JsonApiLaravel\Facades\JsonApi;

JsonApi::discover([app_path('Api/Resources')]);      // add a scan path
JsonApi::register([\App\Odd\PlaceResource::class]);  // register a class explicitly
```

## `#[AsJsonApiResource]`

The attribute is optional — a bare `AbstractResource` gets all five CRUD operations on the
`default` server. Add it to carry metadata:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: ['default', 'admin'], tags: ['Catalog'])]
final class AlbumResource extends AbstractResource { /* … */ }
```

| Parameter | Purpose |
| --- | --- |
| `type` | declaration-site `$type` override (rare) |
| `server` | server name, list of names, or `null` for the implicit `default` |
| `model` | the Eloquent model backing this type when the name diverges from the convention guess ([eloquent](eloquent.md#the-model-map-three-tiers)) |
| `serializer` / `hydrator` | per-concern override classes ([custom-serializers-hydrators](custom-serializers-hydrators.md)) |
| `operations` | the exposed operation allow-list (`Operation` cases); empty = all five |
| `readOnly` | shorthand for the two fetch operations (mutually exclusive with `operations`) |
| `policy` | a dedicated API policy class ([authorization](authorization.md)) |
| `abilities` | per-operation Gate ability override ([authorization](authorization.md)) |
| `cacheHeaders` | declarative `Cache-Control`/`Vary` for GET reads (below) |
| `deprecation` / `sunset` / `sunsetLink` | RFC 8594 deprecation headers (below) |
| `tags` | OpenAPI tag names for the type's operations ([openapi](openapi.md)) |

### Restricting operations

```php
use haddowg\JsonApiLaravel\Operation\Operation;

// Read-only, two ways:
#[AsJsonApiResource(readOnly: true)]
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
```

Only the allowed operations get a route; the rest 404. The `public-profiles` type in the
example is read-only this way.

## Sourcing the resource id

The `Id` field chooses the id strategy — every strategy the example exercises:

```php
use haddowg\JsonApi\Resource\Field\Id;

Id::make();                                              // store-provided (auto-increment)
Id::make()->requireClientId()->pattern('^[a-z0-9-]+$'); // client-supplied natural key
Id::make()->uuid()->generated();                        // app-minted UUID v4
Id::make()->ulid()->generated();                        // app-minted ULID
Id::make()->encodeUsing(new ProductIdCodec())->matchAs('prod-[0-9a-f]+'); // opaque encoded id
```

- **Store-provided** (`Id::make()`) — the database assigns the key; `POST` carries no id.
- **Client natural key** (`requireClientId()`) — a `POST` with no id is a `403`
  (ClientGeneratedIdRequired); a value failing `pattern()` is a `422` at `/data/id`. The
  example's `genres` uses a lowercase-slug key.
- **App-minted** (`uuid()->generated()` / `ulid()->generated()`) — the package mints the id
  before persistence. `playlists` uses a UUID, `devices` a ULID.
- **Encoded** (`encodeUsing()`) — a DB integer key never appears on the wire; an
  `IdEncoderInterface` (framework-neutral core interface — the example's `ProductIdCodec`)
  maps it to an opaque token. `matchAs()` pins the route `{id}` regex so a malformed token is
  a route miss.

`matchAs()` becomes a `->where('id', …)` route constraint — see [routing](routing.md).

## Computed and write-only fields

```php
use haddowg\JsonApi\Resource\Field\Integer;

// A read-only value derived at serialize time (no column):
Integer::make('trackCount')->computed()->readOnly()
    ->extractUsing(static fn(mixed $m): int => (int) Accessor::get($m, 'track_count'));

// Accepted and validated on write, never rendered (a credential):
Str::make('password')->writeOnly()->minLength(8)->requiredOnCreate();
```

`readOnlyOnUpdate()` accepts a field on create but freezes it on `PATCH` (the example's
`createdAt`, `favoritedAt`).

## Sparse-by-default fields

For a field that *is* part of the type but is **too expensive to render every time**, mark it
`sparseByDefault()`:

```php
Integer::make('expensiveScore')->storedAs('expensive_score')->sparseByDefault(),
```

It is then omitted from the default response and rendered **only** when the client explicitly
names it in `fields[type]`:

```
GET /sparseWidgets/1                                            → no expensiveScore
GET /sparseWidgets/1?fields[sparseWidgets]=name,expensiveScore  → expensiveScore included
```

Because the field is dropped before its value hook runs, the expensive computation is skipped
on every request that does not ask for it. It stays a fully declared member — a valid
`fields[type]` name, documented in the schema — so, unlike a curated-out field, naming it is
*not* rejected. This is the opt-in inverse of the usual sparse fieldset (present unless
excluded), and is orthogonal to `hidden()` / `writeOnly()` (never rendered even when named).
Applies to relations too.

Witnessed on both providers by
`tests/Conformance/SparseByDefaultConformanceTestCase` (core ADR 0117).

## Response headers

Declarative HTTP headers on the attribute, layered over `jsonapi.defaults`:

```php
#[AsJsonApiResource(
    cacheHeaders: [
        'max_age' => 3600, 's_maxage' => 86400, 'public' => true, 'vary' => ['Accept'],
        'operations' => ['collection' => ['max_age' => 300]],
    ],
)]
final class GenreResource extends AbstractResource { /* … */ }
```

`cacheHeaders` apply only to a successful `GET`, with an optional per-read-shape
(`collection`/`read`/`related`/`relationship`) override. RFC 8594 deprecation rides **every**
response:

```php
#[AsJsonApiResource(
    deprecation: true,
    sunset: 'Sat, 31 Dec 2050 23:59:59 GMT',
    sunsetLink: 'https://music.example/deprecations/devices',
)]
final class DeviceResource extends AbstractResource { /* … */ }
```

## Object-aware `getType()` and the self-link opt-out

A resource that serves as a **polymorphic member** (of a `MorphTo`/`MorphToMany`) overrides
`getType()` so core resolves the wire type from the object:

```php
public function getType(mixed $object): string
{
    return $object instanceof Artist ? 'artists' : '';
}
```

A type can suppress the convention `data.links.self` (the example's `devices`):

```php
public function emitsSelfLink(): bool
{
    return false;
}
```

## Default includes and include-path safeguards

Override the includes rendered when the client sends no `?include`, and whitelist the dotted
paths a client may request:

```php
public function getDefaultIncludedRelationships(mixed $object): array
{
    return ['artist'];   // GET /albums/1 rides the artist in `included`
}

public function getAllowedIncludePaths(): array
{
    return ['playlists', 'playlists.owner', 'library']; // playlists.tracks → 400
}
```

Both are exercised by the example (`albums` default include; `users` include whitelist). See
[relationships](relationships.md#include).

## Two types, one model

Two resource types may back the same Eloquent model — the example's admin-only `users` and
the public, narrower `public-profiles` both map to `User`. The curation is the field
inventory: `public-profiles` declares only `displayName`, so no sparse fieldset or include
can resurface the private columns. Convention cannot guess a shared model (nothing named
`PublicProfile` exists), so each such type declares it —
`#[AsJsonApiResource(model: User::class)]` — or the
[explicit map](eloquent.md#the-model-map-three-tiers) covers both. See
[capability-composition](capability-composition.md#one-model-two-types).
