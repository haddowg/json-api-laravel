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

## Relation segments

The `{relationship}` segment is the relation's **URI field name**: its name, unless the
relation sets another with `withUriFieldName()`. A relation declared as

```php
HasMany::make('remarks', 'comments')->withUriFieldName('article-remarks');
```

is served at `/api/articles/{id}/article-remarks` and
`/api/articles/{id}/relationships/article-remarks`, and its links and the
[OpenAPI document](openapi.md) name only that segment. As soon as the router matches the
route, the package swaps the `relationship` parameter for the relation's name, so route
middleware, `$request->jsonApi()` and the handler all see `remarks`.

A request to the relation's name (`/api/articles/{id}/remarks`) is still answered when no
relation is served under that segment, so a client written before the rename keeps working.
The OpenAPI document doesn't list that path and no link points at it. A segment that is
neither a URI field name nor a relation name is a `404`, as it always was. An
[Atomic Operations](atomic-operations.md) `href` resolves its segment the same way.

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

With an empty `base_uri`, a server's links carry the prefix its routes matched under, and
the request host stands in for the domain (see
[configuration](configuration.md#prefixes-and-the-base)). A configured `base_uri` must
include the prefix.

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
auto-registration would, inside the surrounding group. Links built from an empty `base_uri`
follow that group's prefix (`/v2` above), because the package reads the prefix off the
matched route rather than from the server config.

## Is this a JSON:API route? {#is-this-a-json-api-route}

Every route the package registers (resources, relationships, custom actions and atomic
operations) carries the `_jsonapi` route default. `$request->isJsonApi()` reads it off the
matched route, so your own middleware can tell the package's routes apart without depending
on its internals:

```php
use Closure;
use Illuminate\Http\Request;

final class AddLegacyEnvelope
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isJsonApi()) {
            return $next($request);   // leave JSON:API responses alone
        }

        // ... the app's own behaviour
        return $next($request);
    }
}
```

The answer comes from the matched route, so it is only meaningful after Laravel's router has
matched one. Route middleware (including a server's `middleware` and a group around
`Route::jsonApi()`) sees the right answer. Global middleware runs before routing and always
gets `false`, as does anything else that runs before the route is matched.

### Which endpoint it is

`$request->jsonApi()` goes further and says which endpoint matched. It returns a
`JsonApiRoute`, or `null` wherever `isJsonApi()` is false:

```php
$route = $request->jsonApi();

$route?->server;                  // 'default', or the named server
$route?->type;                    // 'albums'; null on the atomic route
$route?->id;                      // '1' from /albums/1; null on collection routes
$route?->relationship;            // 'artist' from /albums/1/artist and /albums/1/relationships/artist (the relation's name)
$route?->isRelationshipEndpoint;  // true only for /relationships/{relationship}
$route?->action;                  // 'publish' from /albums/1/-actions/publish
$route?->actionScope;             // ActionScope::Resource or ActionScope::Collection
$route?->isAtomic;                // true on the atomic operations route
$route?->target();                // core's Target for the endpoint; null on the atomic route
```

`id` is the route parameter as the URL carried it, and `relationship` is the name of the
relation the URL addressed, even when the URL used its URI field name (see
[relation segments](#relation-segments)). Nothing is loaded:
there is no model on a `JsonApiRoute`, and middleware runs before the package fetches one.
Code that needs the model belongs in a [lifecycle hook](lifecycle-hooks.md) or a
[custom action](actions.md), which receive it.

There is no `Server` on it either. Resolve the server for the request from the container,
which bases its links where the request found it (see
[configuration](configuration.md#prefixes-and-the-base)):

```php
use haddowg\JsonApiLaravel\Server\ServerRegistry;

$server = app(ServerRegistry::class)->forRequest($request);
```

### Without the macros

Both macros delegate to static methods on `JsonApiRoute`, for code that prefers a typed call:

```php
use haddowg\JsonApiLaravel\Routing\JsonApiRoute;

JsonApiRoute::matches($request);   // isJsonApi()
JsonApiRoute::of($request);        // jsonApi()
JsonApiRoute::MARKER;              // '_jsonapi', the route default both check
```

Larastan and the IDE helpers that read Laravel's registered macros (barryvdh/laravel-ide-helper)
pick the macros up from the booted application, so `$request->jsonApi()?->type` is typed
`?string`. Plain PHPStan without Larastan doesn't know about macros, so call the static
methods there.

## `route:cache` safety {#route-cache-safety}

Route registration reads the **cached discovery snapshot** rather than re-scanning the
filesystem, so `php artisan route:cache` works. Warm the snapshot with the
[`optimize`](optimize.md) pipeline (`php artisan jsonapi:optimize`) before caching routes in
production; in development the scan runs live on first boot.
