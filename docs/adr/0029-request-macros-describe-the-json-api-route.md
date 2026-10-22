# Request macros describe the JSON:API route

Applications that skip or inspect the package's routes in their own middleware had to read the
`_jsonapi` route default through `JsonApiExceptionRenderer::ROUTE_MARKER`, which ties app code to
the exception renderer. The marker now lives on `Routing\JsonApiRoute`, a readonly value object:
`JsonApiRoute::matches()` and the `$request->isJsonApi()` macro say whether a JSON:API route
matched, and `JsonApiRoute::of()` and `$request->jsonApi()` return its server, type, id,
relationship, action and atomic facts, read from the same route defaults and parameters the
`TargetResolver` and controller read. The renderer constant stays as a deprecated alias. This is
the Laravel twin of the Symfony bundle's ADR 0122, with the same names on both sides. Both read
the matched route, so global middleware, which runs before routing, gets `false` and `null`;
that is documented rather than hidden behind a second route match.

## Considered options

- **Carry the Server or the model, as laravel-json-api's `JsonApi::route()` does.** Building the
  Server needs the container, and since ADR 0028 its base depends on the request, so the value
  object would either go stale or need a container reference. `ServerRegistry::forRequest()` is
  the documented way to get it. Loading the model (`modelOrFail()`) would mean a provider query
  in middleware, ahead of the handler that already loads it; hooks and actions receive the model.
- **A `@method` tag or an ide-helper stub for the macros.** `Illuminate\Http\Request` is a
  framework class, so the package can't add a `@method` tag to it, and a shipped stub file only
  helps apps that load it. Larastan boots the application and reads `Request`'s registered
  macros, so an app that has the package installed already gets them type-checked; the
  package's own analysis gets them the same way, because Larastan boots the providers listed in
  `testbench.yaml`. Plain PHPStan without Larastan has the static methods.
