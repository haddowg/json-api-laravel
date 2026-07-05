# Routing

Routes register **themselves** from `config/jsonapi.php` — one route per discovered type ×
allowed operation, per server. This is the opposite of the Symfony bundle (where you import a
route type by hand). This page covers the auto-registration, the manual-placement macro, route
names, and `route:cache` safety (PLAN decision 4).

## Auto-registration

On boot, the package registers every allowed operation for every discovered resource under its
server's `prefix`, `middleware`, and optional `domain` from `config/jsonapi.php`:

```
GET    /api/albums                          jsonapi.albums.index
GET    /api/albums/{id}                      jsonapi.albums.show
POST   /api/albums                           jsonapi.albums.create
PATCH  /api/albums/{id}                      jsonapi.albums.update
DELETE /api/albums/{id}                      jsonapi.albums.delete
GET    /api/albums/{id}/{relationship}       …  # related + relationship read/mutation
```

Only the operations a resource allows are emitted (`readOnly`/`operations` — see
[resources](resources.md#restricting-operations)); the rest simply don't exist.

## Route names

Route names are stable and predictable:

- **default server:** `jsonapi.{type}.{action}` — e.g. `jsonapi.albums.show`;
- **named server:** `jsonapi.{server}.{type}.{action}` — e.g. `jsonapi.admin.albums.show`.

Use them with `route()` and `URL::route()` as normal. The docs routes follow the same scheme
(`jsonapi.openapi.json`, `jsonapi.admin.openapi.json`) — see [openapi](openapi.md).

## Id route constraints

An `Id` field's `matchAs()` becomes a `->where('id', …)` route constraint, so a malformed id
is a clean route miss (`404`) rather than a `500` deep in the provider:

```php
Id::make()->encodeUsing(new ProductIdCodec())->matchAs('prod-[0-9a-f]+');
// → route {id} constrained to prod-[0-9a-f]+
```

`uuid()` / `ulid()` id strategies apply their natural format constraint automatically.

## Server middleware and domain

Attach middleware or a host constraint per server in the config:

```php
'servers' => [
    'default' => ['prefix' => 'api',   'middleware' => ['throttle:api'], 'domain' => null],
    'admin'   => ['prefix' => 'admin', 'middleware' => ['auth:sanctum'], 'domain' => 'admin.example.test'],
],
```

## Manual placement

Opt out of auto-registration and place the routes yourself with the `Route::jsonApi()` macro —
useful to nest them inside your own route group (extra middleware, a versioned prefix):

```php
// A service provider's register():
use haddowg\JsonApiLaravel\Facades\JsonApi;
JsonApi::ignoreRoutes();

// routes/api.php:
Route::middleware('auth:sanctum')->prefix('v2')->group(function () {
    Route::jsonApi();          // the default server's routes here
    Route::jsonApi('admin');   // a named server's routes here
});
```

`Route::jsonApi(?string $server = null)` emits the same operation-gated route set the
auto-registration would, inside the surrounding group.

## `route:cache` safety {#route-cache-safety}

Route registration reads the **cached discovery snapshot** rather than re-scanning the
filesystem, so `php artisan route:cache` works. Warm the snapshot with the
[`optimize`](optimize.md) pipeline (`php artisan jsonapi:optimize`) before caching routes in
production; in development the scan runs live on first boot.
