# Custom actions

Not everything is CRUD. A **custom action** is a non-CRUD endpoint hanging off a JSON:API type
under the reserved `-actions` segment — "reissue this album", "summarize the catalogue",
"upload artwork". Declare a handler with `#[AsJsonApiAction]`; discovery finds it and
registers its route (PLAN decision 12). The three examples below are the music-catalog
workbench's `albums` actions.

## A resource-scoped action

The default scope mounts `POST /{type}/{id}/-actions/{path}` and resolves the `{id}` to an
entity before the handler runs:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApi\Response\DataResponse;

#[AsJsonApiAction(type: 'albums', path: 'reissue', input: ActionInput::Document, ability: 'reissueAlbum')]
final readonly class ReissueAlbum implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $album = $context->entity();      // the resolved album
        // … mutate + persist …
        return $context->data($persisted); // rendered through the albums serializer
    }
}
```

```
POST /api/albums/1/-actions/reissue
```

`ability: 'reissueAlbum'` gates it through the package [authorizer](authorization.md#custom-actions).
`input: ActionInput::Document` parses + validates + hydrates a JSON:API document into the mount
type before `handle()`.

## A collection-scoped, meta-only action

`scope: ActionScope::Collection` mounts `POST /{type}/-actions/{path}` with no id;
`responds: [new MetaResult()]` declares a meta-only `200` response:

```php
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;

#[AsJsonApiAction(type: 'albums', path: 'summary', scope: ActionScope::Collection, responds: [new MetaResult()], tags: ['Catalog'])]
final class SummarizeAlbums implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['released' => /* … */, 'upcoming' => /* … */]);
    }
}
```

```
POST /api/albums/-actions/summary
```

## A raw-input, `204` action

`input: ActionInput::Raw` relaxes content-type negotiation for a non-JSON:API upload;
`responds: [new NoContent()]` declares a bodyless `204` response:

```php
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;

#[AsJsonApiAction(type: 'albums', path: 'artwork', input: ActionInput::Raw, responds: [new NoContent()])]
final class UploadAlbumArtwork implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        // read the uploaded file / raw body off the request, attach, persist
        return $context->noContent();
    }
}
```

```
POST /api/albums/1/-actions/artwork
```

A raw action that lists no media types takes a body of any type except
`multipart/form-data`, `application/x-www-form-urlencoded` and `text/plain`. Browsers send
those three cross-origin without a preflight, so an action takes them only when it names
them in `accepts`. A file upload therefore declares
`accepts: ['multipart/form-data']`.

## Choosing the media types an action takes

`accepts` lists the request media types an action takes. Each entry in a plain list is read
in the action's `input` mode. A `mediaType => ActionInput` entry carries its own mode, so one
action can take a JSON:API document or a CSV file:

```php
#[AsJsonApiAction(
    type: 'albums',
    path: 'import',
    scope: ActionScope::Collection,
    responds: [new MetaResult()],
    accepts: ['application/vnd.api+json' => ActionInput::Document, 'text/csv' => ActionInput::Raw],
)]
```

The request's `Content-Type` picks the entry. A document entry is validated and hydrated into
`$context->input()` as usual; a raw entry leaves the body on `$context->request()`.
`$context->requestMediaType()` returns the media type the body was sent as, lowercased and
without parameters, such as `text/csv`. Wildcards such as `image/*` match, but never one of
the three form types above. A type the action doesn't list is a `415`. The OpenAPI document
lists each entry under the operation's `requestBody.content`.

### Which requests must name their media type

Every `POST` must send a `Content-Type`, even with no body, and so must a `PATCH`, `PUT` or
`DELETE` that has a body. CRUD writes, relationship writes, atomic batches and actions with
`Document` or `None` input take only `application/vnd.api+json` (atomic batches with the
atomic `ext`). Any other type, or none, is a `415`. A `GET`, or a `DELETE` with no body, needs
no `Content-Type`.

A `POST` with no body and the `application/vnd.api+json` `Content-Type` reaches any action,
whatever its `accepts` lists, and the handler sees no body. The JSON:API clients send that
header on every `POST`, and a browser can't send it cross-site without a preflight. A request
that has a body must still match `accepts`.

## The attribute

| Parameter | Purpose |
| --- | --- |
| `type` | the **mount type** — the URL segment the action hangs off, and the default request/response type |
| `path` | the single `{action}` URL segment |
| `scope` | `Resource` (default, resolves `{id}`) or `Collection` |
| `methods` | HTTP verb allow-list (default `['POST']`) |
| `input` | `None` (default) / `Document` (parse + validate + hydrate) / `Raw` (relaxed negotiation) |
| `inputType` | decouple the **request** document type from the mount type |
| `responds` | the advertised success response(s): a single object or a list of `new ActionResource('type')` (`200` document — default is the mount type), `new MetaResult()` (`200` meta), `new NoContent()` (`204`), `new Accepted('job-type')` (`202`), `new SeeOther()` (`303`), from `haddowg\JsonApi\OpenApi\Metadata` |
| `ability` | the Gate ability checked before the handler ([authorization](authorization.md)) |
| `server` / `name` / `tags` | server exposure, route-name override, OpenAPI tags |
| `asLink` | expose the action as an ability-aware `links` member on the mount type's resources (resource scope only) |
| `accepts` | the request media types the action takes: a list read in `input` mode, or `mediaType => ActionInput` ([below](#choosing-the-media-types-an-action-takes)) |
| `summary` / `description` | the operation's OpenAPI `summary` and `description`; omitted, the generated ones stay |

> [!NOTE]
> `asLink` visibility matches invocation **exactly**: `Authorizer::allowsAction()` (which
> decides whether to render the link) simply wraps `authorizeAction()` (which enforces it) in
> a try/catch, so both pass when the action declares no ability. A declared ability that no
> policy or `Gate::define()` answers denies both the call and the link. A client never sees a link to an action it could
> not invoke, and never loses a link to one it could.

> [!NOTE]
> `responds` replaces the former `outputType` / `outputMeta` / `returns204` params. Migrate
> `outputMeta: true` → `responds: [new MetaResult()]`, `returns204: true` → `responds: [new NoContent()]`,
> and `outputType: 'x'` → `responds: [new ActionResource('x')]`. An **asynchronous** action
> advertises `responds: [new Accepted('job-type'), new SeeOther()]` — see [async writes](async.md).

## A raw response: CSV, PDF and other bodies

An action can answer with a body that is not a JSON:API document. Declare each media type
with a `RawResult` beside, or instead of, its JSON:API responses, and return
`$context->raw()`:

```php
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;

#[AsJsonApiAction(
    type: 'albums',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [new MetaResult(), new RawResult('text/csv', schema: ['type' => 'string']), new RawResult('application/pdf', binary: true)],
)]
final class ExportAlbums implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse|RawResponse
    {
        return match ($context->responseMediaType()) {
            'text/csv' => $context->raw($csv, headers: ['Content-Disposition' => 'attachment; filename="albums.csv"']),
            'application/pdf' => $context->raw($pdfStream),
            default => $context->meta(['count' => $count]),
        };
    }
}
```

Before the handler runs, the request's `Accept` header picks one of the declared media types
(q-values and wildcards count, and JSON:API wins a tie). `$context->responseMediaType()`
returns it, and `$context->raw()` uses it as the `Content-Type` unless you pass another. An
`Accept` the action can't answer is a `406`. A raw body may be a string, a stream resource or
a PSR-7 stream; a stream goes out as a `StreamedResponse`. Returning a raw body in a media
type that wasn't negotiated is a server error. Errors always render as JSON:API documents.
Each `RawResult` appears under the success response's `content` in the OpenAPI document.

## `ActionContext`

The handler's single argument gives you the resolved entity (resource scope), the hydrated
input (Document mode), the request, and the response builders — `$context->data($entity)`,
`$context->meta([...])`, `$context->noContent()`. Its shape drives the OpenAPI projection so
the generated document matches what the handler returns.

## Actions in the OpenAPI document

Each action projects an operation under its mount type's tag (or its own `tags`). A secured
action carries its security requirement; its `responds` set becomes the advertised success
responses (a `200` document or meta, `204`, `202`, `303`). Its `summary` and `description`
are generated from the path and mount type unless the attribute sets them:

```php
#[AsJsonApiAction(
    type: 'albums',
    path: 'publish',
    summary: 'Publish an album',
    description: 'Sets the album status from the request and returns the updated album.',
)]
```

See [openapi](openapi.md).
