# Errors

Every failure on a JSON:API route renders as a spec-compliant JSON:API **error document**.
The package registers a **route-scoped renderable** on Laravel's exception handler that owns
errors only on JSON:API routes — your app's own error handling elsewhere is untouched. The
error model, the status catalogue, and the pointer conventions are core's (see core
[errors](https://github.com/haddowg/json-api/blob/main/docs/errors.md)); this page covers the
Laravel mapping.

## The mapping pipeline

An exception thrown on a JSON:API route is mapped in order, first match wins:

1. **A core `JsonApiExceptionInterface`** renders natively at its own status (a
   `ResourceNotFound` → `404`, a validation bag → `422`, a `ClientGeneratedIdRequired` →
   `403`, …) — never intercepted or overridden.
2. **Your tagged exception mappers** — first non-null result wins (below).
3. **A Laravel/Symfony `HttpExceptionInterface`** (a `NotFoundHttpException`,
   `ConflictHttpException`, …) maps to a status-keyed error.
4. **`AuthorizationException` → `403`**, **`AuthenticationException` → `401`**.
5. **Anything else → `500`** via core's `InternalServerError`, with
   `{exception, file, line, trace}` in the error `meta` **gated on `app.debug`**.

```json
{ "errors": [ {
  "status": "404",
  "title": "Not Found",
  "detail": "No albums resource matches the id \"999\"."
} ] }
```

Because the exception reaches this renderable through Laravel's handler, Laravel has already
logged an unexpected `500` — the renderable does not log again (no double logging).

## Throwing an error from your code

Throw a standard Laravel/Symfony HTTP exception and it maps by status. The example's
`playlists` before-delete guard refuses a non-empty playlist with a `409`:

```php
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

throw new ConflictHttpException('Cannot delete a playlist that still has tracks.');
```

The message becomes the error `detail` (debug-gated for a `500`, always present for a
4xx). For full control, throw a core `JsonApiExceptionInterface` with your own error
objects, pointers, and meta.

## Custom exception mappers

Map your own domain or third-party exceptions to a JSON:API error by implementing
`ExceptionMapperInterface` and tagging it — the extension point that runs at step 2, before the
generic arms:

```php
use haddowg\JsonApiLaravel\Exception\ExceptionMapperInterface;
use haddowg\JsonApi\Response\ErrorResponse;

final class PaymentFailedMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $throwable): ?ErrorResponse
    {
        if (! $throwable instanceof PaymentFailed) {
            return null;   // not mine — fall through
        }

        return ErrorResponse::fromErrors(/* … a core Error VO at 402 … */);
    }
}
```

Register it in a service provider by binding + tagging with `jsonapi.exception_mapper`:

```php
$this->app->bind(PaymentFailedMapper::class);
$this->app->tag([PaymentFailedMapper::class], 'jsonapi.exception_mapper');
```

Returning `null` falls through to the next mapper, then the generic arms. This is how you map
an exception without decorating the whole renderer.

## Content negotiation and query errors

The request layer rejects a bad request before your code runs, all as JSON:API errors: a
wrong `Accept` is a `406`, a wrong `Content-Type` a `415`, an unknown query-parameter family a
`400` (strict query parameters — see [configuration](configuration.md)), a malformed document
a `400`/`422`, and an over-deep `?include` a `400`.

## Localizing and overriding error copy

Every error's `title` and `detail` are message templates core resolves per stable error
`code`
([core ADR 0128](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md#localizing-and-overriding-error-copy)).
The package binds that seam to the **Laravel translator** automatically: localize or rebrand
any error's copy through ordinary translation files in the `jsonapi-errors` group, keyed by
code:

```php
// lang/fr/jsonapi-errors.php
return [
    'RESOURCE_NOT_FOUND' => ['title' => 'Ressource introuvable'],
    'MEDIA_TYPE_UNSUPPORTED' => [
        'detail' => "Le type de média '{mediaType}' n'est pas supporté.",
    ],
    'VALIDATION_FAILED' => ['title' => 'Entité non traitable'],
];
```

Only the human copy moves: an error's `code` and `status` are never touched, and a line you
don't provide falls back to core's inline English — per slot, so a partial translation is
fine. The values are **templates**: a `{placeholder}` is filled from the error's context
*after* lookup (a media type, an id), so write `{mediaType}`, not Laravel's `:mediaType` (no
replacements are passed to the translator). The placeholder names available per code are
listed in core's
[errors-and-exceptions](https://github.com/haddowg/json-api/blob/main/docs/errors-and-exceptions.md#localizing-and-overriding-error-copy).

The lookup uses the app's current locale, so `Accept-Language` negotiation is the framework's
job — set the locale with Laravel's usual `App::setLocale()` (or a locale middleware) and each
request renders in its language. Because the resolver is applied uniformly to every error, the
validator's `422` `VALIDATION_FAILED` title localizes through the very same group. With no
`jsonapi-errors` lines the seam is inert and errors render in English exactly as before.
