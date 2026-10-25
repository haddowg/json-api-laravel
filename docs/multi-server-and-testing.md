# Multi-server & testing

Two cross-cutting capabilities: exposing several JSON:API servers from one app, and testing
JSON:API endpoints with a Laravel-native kit.

## Multi-server

Each entry in `jsonapi.servers` is an independent API surface — its own URI `prefix`, route
`middleware`, and optional `domain`. A single-API app needs only `default`; add a named server
for a versioned or audience-scoped surface. The example adds `admin`:

```php
'servers' => [
    'default' => ['prefix' => 'api',   'middleware' => [], 'domain' => null],
    'admin'   => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
],
```

A resource joins servers on its attribute:

```php
#[AsJsonApiResource(server: ['default', 'admin'])]   // exposed on both
final class AlbumResource extends AbstractResource { /* … */ }

#[AsJsonApiResource(server: 'admin')]                 // admin-only — /users 404s, /admin/users resolves
final class UserResource extends AbstractResource { /* … */ }
```

A shared type drags its related endpoints' targets along: `GET /admin/albums/1/artist`
returns an `artists` resource object, so `artists` has to be registered on `admin` too. The
workbench's `artists`, `tracks`, `playlists`, `libraries` and `public-profiles` are all
shared for that reason, and `jsonapi:optimize` fails the deploy when one is missing — see
[relationships](relationships.md#the-related-endpoints-target-must-be-registered-on-the-server).

### Links per server

Each server links under its own prefix. With an empty `base_uri` that happens on its own: a
request to `/api/albums/1` links `http://host/api/albums/1` and one to `/admin/albums/1`
links `http://host/admin/albums/1`. A single global `base_uri` can only carry one prefix, so
give each server its own `base_uri` when you pin a canonical host. The music-catalog
workbench does:

```php
'base_uri' => 'https://music.example/api',
'servers' => [
    'default' => ['prefix' => 'api',   'middleware' => [], 'domain' => null],
    'admin'   => ['prefix' => 'admin', 'middleware' => [], 'domain' => null, 'base_uri' => 'https://admin.music.example/admin'],
],
```

A server without its own `base_uri` inherits the global one. See
[configuration](configuration.md#prefixes-and-the-base).

Route names carry the server: `jsonapi.admin.albums.show`. OpenAPI projects one document per
server by default (`/docs.json`, `/admin/docs.json`) — see [openapi](openapi.md) and
[routing](routing.md). The same type may render differently per server (an admin server can
expose more fields via a distinct resource) — see
[capability-composition](capability-composition.md).

### Authorization per server

Two servers can expose the same type through different resource classes, and each server
enforces only what its own class declares on `#[AsJsonApiResource]`: its `policy:` and its
`abilities` renames, `false` and `true` included. A request to `/admin/documents` checks the
admin class's policy, and one to `/api/documents` checks the default class's. That holds
everywhere a check runs: the endpoints, the related type's gates on an `?include`, the
`view{Relation}` convention, custom actions and each server's OpenAPI document.

```php
#[AsJsonApiResource(policy: DocumentPolicy::class)]
final class DocumentResource extends AbstractResource { /* type 'documents' */ }

#[AsJsonApiResource(server: 'admin', policy: AdminDocumentPolicy::class, abilities: [
    Operation::Update->value => 'edit',
])]
final class AdminDocumentResource extends AbstractResource { /* type 'documents' */ }
```

A class on several servers applies its declarations on each of them. A server whose class
declares nothing for a type gets nothing from another server's class: the type falls back to
the model's Gate policy there, the same as in a single-server app. That fallback is per model,
so a `Gate::policy()` mapping applies on every server. A check dispatched in code, with no
routed request behind it, reads the server the operation was built on, and the `default`
server when there is none. See [ADR 0052](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0052-authorization-is-keyed-by-server-and-type.md).

### Output timezone per server

Each server can render `DateTime` attributes in its own zone. A server without an
`output_timezone` inherits the global one, and `''` turns conversion off for that server
only:

```php
'output_timezone' => 'UTC',
'servers' => [
    'default'  => ['prefix' => 'api'],                                         // UTC
    'partners' => ['prefix' => 'partners', 'output_timezone' => 'America/New_York'],
    'internal' => ['prefix' => 'internal', 'output_timezone' => ''],          // as stored
],
```

A resource shared by several servers renders in the zone of the server whose route
matched. Each server holds its own instance of the resource, even when you bind the
resource as a singleton: the first server gets the container's instance and every other
server a clone of it, so one server's zone or action links never leak into another's. See [configuration](configuration.md#output_timezone).

## Testing

The kit reshapes the bundle's `JsonApiBrowser` into Laravel testing grammar (PLAN decision 12):
an `InteractsWithJsonApi` trait for building requests, and `TestResponse` macros for asserting
responses. `actingAs()` is native Laravel.

### The request builder

`use InteractsWithJsonApi;` gives your test case a `jsonApi()` builder with a typed query DSL
and body sugar — no hand-encoded query strings or JSON:API envelopes:

```php
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;

final class AlbumTest extends TestCase
{
    use InteractsWithJsonApi;

    public function test_it_lists_and_filters_albums(): void
    {
        $this->jsonApi()
            ->withInclude('artist')
            ->withFilter('title', 'comp')
            ->withSort('-releasedAt')
            ->withPage(['number' => 1, 'size' => 10])
            ->get('/api/albums')
            ->assertFetchedMany();
    }

    public function test_it_creates_an_album(): void
    {
        $this->actingAs($user)                         // native Laravel auth
            ->jsonApi()
            ->withResource('albums', attributes: ['title' => 'Kid A'])
            ->post('/api/albums')
            ->assertJsonApiDocument(fn ($doc) => $doc->assertResource('albums'));
    }
}
```

Builder highlights: `withResource()` / `withData()` / `withDocument()` (body), `withInclude()`,
`withFields()`, `withSort()`, `withFilter()`, `withPage()`, `withProfile()`, `withHeader(s)`,
then `get()` / `post()` / `patch()` / `delete()`.

### The assertion macros

Registered on Laravel's `TestResponse`, so they compose with the native assertions:

| Macro | Asserts |
| --- | --- |
| `assertJsonApiDocument(?Closure)` | a well-formed JSON:API success document (+ optional callback over it) |
| `assertJsonApiError(?int $status, ?Closure)` | a JSON:API error document (+ optional status/callback) |
| `assertFetchedOne(?Closure)` | a `200` single-resource document |
| `assertFetchedMany(?Closure)` | a `200` collection document |
| `assertJsonApiSpecCompliant()` | the response validates against the JSON:API 1.1 JSON Schema (opis-gated) |
| `jsonApiDocument()` / `jsonApiErrors()` | extract the parsed document / errors for custom assertions |

```php
$response->assertJsonApiError(404, fn ($errors) => $errors->assertHasError(/* … */));
$id = $response->jsonApiDocument()->id();   // chain create → fetch
```

### Schema conformance

With `opis/json-schema` installed, `SchemaConformanceTrait` validates real responses against
the generated OpenAPI component schemas, and `assertJsonApiSpecCompliant()` becomes active. A
test fails as soon as a response drifts from the document you publish. Without opis both are
skipped rather than failed.

```php
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use haddowg\JsonApiLaravel\Testing\SchemaConformanceTrait;
use haddowg\JsonApiLaravel\Testing\SchemaDocumentKind;

final class AlbumSchemaTest extends TestCase
{
    use InteractsWithJsonApi;
    use SchemaConformanceTrait;

    public function test_albums_match_the_published_schema(): void
    {
        $response = $this->jsonApi()->get('/api/albums/1')->assertOk();

        $this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);
    }
}
```

`SchemaDocumentKind` picks the envelope: `Single`, `Collection`, `Relationship` and `Related`
(the last two take the relation name as the fourth argument). Pass `server:` to validate
against a named server's document. `assertBodyMatchesGeneratedComponent($body, 'AlbumsResource')`
validates a raw body against any component by name, and `bodyMatchesGeneratedComponent()`
returns the result as a bool for tests that expect a body to fail.

#### Included resources

The envelope schemas type `included` loosely, as objects with a `type` and an `id`. So once the
envelope passes, the helpers validate each `included` resource against its own type's
`<Type>Resource` component, e.g. `ArtistsResource` for an included artist. An included resource
that is missing an attribute, or carries one of the wrong type, fails the assertion with a
message naming its index, such as `/included/0 does not validate against "ArtistsResource"`. A
type the document has no `<Type>Resource` component for is skipped.

#### Sparse fieldsets

The generated read schemas list every attribute as `required`, since a full response carries
them all and generated clients rely on that. A `?fields[albums]=title` response leaves the
other attributes out, so the helpers relax `required` to match. For every type the request
names in `fields[…]`, only the members in that type's fieldset stay required, wherever that
type's resource schema applies: primary data, `included` resources, and primary data of
another type, such as the `artists` returned by `/api/albums/1/artist`. An empty fieldset
(`fields[artists]=`) requires none of that type's members. Types the request doesn't name keep
their full `required` list, so `?include=artist&fields[albums]=title,artist` still checks every
attribute of the included artist. A member that is in the fieldset but missing from the body
still fails.

The helpers read the fieldsets from the request behind the `TestResponse`, so a sparse request
needs no extra argument:

```php
$response = $this->jsonApi()
    ->withInclude('artist')
    ->withFields('albums', 'title', 'artist')
    ->withFields('artists', 'name')
    ->get('/api/albums/1');

$this->assertResponseMatchesGeneratedSchema($response, 'albums', SchemaDocumentKind::Single);
```

When you validate a raw body string, there's no request to read, so pass `fieldsets:` yourself.
Each value is the wire form or a list of member names. An explicit `fieldsets:` also replaces
whatever a `TestResponse`'s request carried, and `fieldsets: []` turns the relaxation off.

```php
$this->assertBodyMatchesGeneratedComponent($body, 'AlbumsDocument', fieldsets: ['albums' => 'title,artist']);
$this->bodyMatchesGeneratedComponent($body, 'AlbumsDocument', fieldsets: ['albums' => ['title']]);
```

### Testbench

The package's own suite (and the [workbench](workbench.md)) run on `orchestra/testbench`. Your
application tests use the standard Laravel `TestCase` — the trait and macros work in both.
