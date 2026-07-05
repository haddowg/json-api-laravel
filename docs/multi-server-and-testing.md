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

Route names carry the server: `jsonapi.admin.albums.show`. OpenAPI projects one document per
server by default (`/docs.json`, `/admin/docs.json`) — see [openapi](openapi.md) and
[routing](routing.md). The same type may render differently per server (an admin server can
expose more fields via a distinct resource) — see
[capability-composition](capability-composition.md).

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
| `assertJsonApiSpecCompliant()` | the response validates against the generated OpenAPI schemas (opis-gated) |
| `jsonApiDocument()` / `jsonApiErrors()` | extract the parsed document / errors for custom assertions |

```php
$response->assertJsonApiError(404, fn ($errors) => $errors->assertHasError(/* … */));
$id = $response->jsonApiDocument()->id();   // chain create → fetch
```

### Schema conformance

With `opis/json-schema` installed, `SchemaConformanceTrait` validates real responses against
the generated OpenAPI component schemas, and `assertJsonApiSpecCompliant()` becomes active — a
strong regression net that a response never drifts from its own contract. Without opis both
degrade gracefully (the conformance assertions are skipped).

### Testbench

The package's own suite (and the [workbench](workbench.md)) run on `orchestra/testbench`. Your
application tests use the standard Laravel `TestCase` — the trait and macros work in both.
