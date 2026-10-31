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
| `create` / `update` / `delete` / `fetchOne` / `fetchCollection` | per-operation advertised response shape(s) — see [below](#declaring-response-shapes) |
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

### Declaring response shapes

Each operation advertises a default success response — `POST` → `201`, `PATCH` → `200`,
`DELETE` → `204`, `GET` → `200`. Override it per operation with atomic response objects from
`haddowg\JsonApi\OpenApi\Metadata` (a single object or a list). Each names a spec-valid code
for that operation, so an illegal set is rejected at discovery:

```php
use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;

#[AsJsonApiResource(
    // POST /catalog-exports is always asynchronous → 202 only, never 201:
    create: [new Accepted('export-jobs')],
    // DELETE may answer 204 or a 200 meta-only document:
    delete: [new NoContent(), new MetaResult()],
)]
```

| Object | Code | Valid on |
| --- | --- | --- |
| `new Created()` | `201` | create |
| `new Ok()` | `200` | update, fetchOne, fetchCollection |
| `new NoContent()` | `204` | create, update, delete |
| `new Accepted('job-type')` | `202` | create, update |
| `new SeeOther()` | `303` | fetchOne |
| `new MetaResult()` | `200` (meta) | delete |

The param types are the per-operation marker interfaces, so an out-of-place object (e.g. a
`new SeeOther()` under `create`) is a static-analysis error, not just a runtime one. Common
shapes: **sync** (omit — the default); **always-async** `create: [new Accepted('jobs')]`;
**maybe-async** `create: [new Created(), new Accepted('jobs')]`; a client-generated-id create
that echoes nothing `create: [new NoContent()]`; and an async job resource whose fetch
redirects on completion `fetchOne: [new Ok(), new SeeOther()]`. A `202` names the **job type**
whose document is the accepted body. The whole async lifecycle then appears in the generated
[OpenAPI document](openapi.md#per-operation-response-declarations); see [async writes](async.md).

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
  a route miss. A UUID stored as 16 raw bytes uses the package's `BinaryUuidEncoder`; see
  [UUID keys](eloquent.md#uuid-keys).

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

The OpenAPI read schema types an attribute as nullable unless a value is guaranteed: every
create must supply it, or its `extractUsing()`/`serializeUsing()` hook declares a non-null
return type (`trackCount` above). A read-only field the server always fills is invisible to
that check, so assert it with `guaranteed()`. The example's playlist `slug`, derived from the
required `title` on every write, is one:

```php
Slug::make('slug')->readOnly()->guaranteed(),
```

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
`tests/Conformance/SparseByDefaultConformanceTestCase` (see [core's fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md)).

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

Each server sends the headers of the class it exposes the type through, see
[multi-server](multi-server-and-testing.md#authorization-headers-and-tags-per-server).

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

## Declared links

A link that every resource of a type carries, and that clients should find in the
OpenAPI document, is declared in `links()` next to `fields()`. A file uploaded to a signed
URL is the usual case. The URL, the method and headers to send, and when the URL expires
go in the file's `links`, not its attributes:

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class FileResource extends AbstractResource
{
    public static string $type = 'files';

    public function fields(): array { /* … */ }

    public function links(): array
    {
        return [
            DeclaredLink::make('upload')
                ->describedAs('Where to PUT the file\'s bytes, until it is uploaded.')
                ->meta(
                    Str::make('method')->in(['PUT'])->guaranteed()
                        ->describedAs('The HTTP method to upload with.')->example('PUT'),
                    ArrayHash::make('headers')->guaranteed()
                        ->describedAs('Headers the upload must send.')->example(['Content-Type' => 'image/png']),
                    DateTime::make('expiresAt')->guaranteed()
                        ->describedAs('When the URL stops working.')->example('2026-10-26T12:00:00+00:00'),
                )
                ->linkUsing(static function (mixed $file, JsonApiRequestInterface $request): ?array {
                    \assert($file instanceof File);
                    if ($file->uploaded_at !== null || Gate::denies('upload', $file)) {
                        return null;
                    }
                    $expiresAt = now()->addMinutes(15);
                    $signed = Storage::disk('s3')->temporaryUploadUrl($file->path, $expiresAt);

                    return [
                        'href' => $signed['url'],
                        'meta' => ['method' => 'PUT', 'headers' => $signed['headers'], 'expiresAt' => $expiresAt],
                    ];
                })
                ->example('https://uploads.example.com/f-1?signature=abc'),
            DeclaredLink::make('download')
                ->hrefUsing(static fn(mixed $file): ?string => $file instanceof File && $file->uploaded_at !== null
                    ? '/files/' . $file->getKey() . '/content'
                    : null)
                ->example('https://api.example.com/files/f-1/content'),
        ];
    }
}
```

`hrefUsing()` builds the href, and `metaUsing()` returns the meta values keyed by member
name. `linkUsing()` does both in one closure, which suits a signing call that returns the
URL and its headers together. A closure that returns `null` leaves the link out of that
resource's `links`, so a gate check or a state check hides it per viewer or per resource.

Each meta member is a field, so it brings its schema, description and example, and its
value renders the way the field renders an attribute. A `DateTime` takes a Carbon instance
and follows the server's [output timezone](configuration.md#output_timezone). `type()`, `title()`, `rel()`,
`hreflang()` and `describedBy()` add static link object members. A link with no meta and
no static members, like `download`, renders as a bare URL string; any other link renders
as a link object on every response.

The example app declares one of each. A track's `preview` is a link object with `format`
and `expiresAt` meta, left out when the track has no duration, and an album's `store` is a
bare URL:

```json
"links": {
  "self": {
    "href": "https://music.example/api/tracks/1",
    "describedby": "/docs.json#/paths/~1tracks~1%7Bid%7D/get"
  },
  "preview": {
    "href": "https://cdn.example.com/previews/1.mp3",
    "type": "audio/mpeg",
    "meta": { "format": "mp3", "expiresAt": "2026-01-01T12:00:00+00:00" }
  }
}
```

Declared links render wherever the resource does: a fetch, a collection, `included`, and
the related endpoint of a pivot relation. When two links share a name, a link
`getLinks()` returns wins, then a declared link, then an action link (`asLink` or an [`ActionLink`](actions.md#showing-an-action-link-only-in-some-states)), then the
convention `self`, which can't be declared. A declared link sharing its name with an
action link fails the OpenAPI export. The [OpenAPI document](openapi.md#links-and-describedby)
lists each declared link by name in `<Type>ResourceLinks`. `links()` runs on the live
resource when the document is built, since the discovery cache can't hold its closures.
Core's [links guide](https://github.com/haddowg/json-api/blob/main/docs/links-and-meta.md#declared-links)
has the full builder reference.

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
