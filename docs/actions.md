# Custom actions

Not everything is CRUD. A **custom action** is a non-CRUD endpoint hanging off a JSON:API type
under the reserved `-actions` segment — "reissue this album", "summarize the catalogue",
"upload artwork". Declare a handler with `#[AsJsonApiAction]`; discovery finds it and
registers its route. The three examples below are the music-catalog
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
the three form types above. A type the action doesn't list is a `415`.

To describe a body in the OpenAPI document, list core's `AcceptedBody` instead of a bare media
type. It is read as a JSON:API document when it is one, and as a raw body otherwise:

```php
use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;

accepts: [
    new AcceptedBody('application/json', schema: ['type' => 'object', 'properties' => ['score' => ['type' => 'integer']]]),
    new AcceptedBody('image/*', binary: true),
    'text/plain',
],
```

Core projects each entry under the operation's `requestBody.content`, in declared order, and
adds the `415` response. A JSON:API document entry carries the input type's request schema; an
entry with a `schema` carries it inline, or as a component when it names a `DescribesRawBody`
class; any other entry is a binary string, or an open object for `multipart/form-data`. A
document that used `accepts` was stamped contract 11, and every document now carries the
baseline of 18
([core ADR 0175](https://github.com/haddowg/json-api/blob/main/docs/adr/0175-an-action-may-declare-the-request-bodies-it-accepts.md)).

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
| `responds` | the advertised success response(s): a single object or a list of `new ActionResource('type')` (`200` document — default is the mount type), `new MetaResult()` (`200` meta), `new NoContent()` (`204`), `new Accepted('job-type')` (`202`), `new SeeOther()` (`303`, or a URL with a `mediaType`, [below](#a-download-action-answering-with-a-url)), `new RawResult('text/csv')` (a non-JSON:API body, [below](#a-raw-response-csv-pdf-and-other-bodies)), from `haddowg\JsonApi\OpenApi\Metadata` |
| `ability` | the Gate ability checked before the handler ([authorization](authorization.md)) |
| `server` / `name` / `tags` | server exposure, route-name override, OpenAPI tags |
| `asLink` | expose the action as an ability-aware `links` member on the mount type's resources (resource scope only); a resource's [`ActionLink`](#showing-an-action-link-only-in-some-states) does the same with a condition |
| `accepts` | the request media types the action takes: a list read in `input` mode, or `mediaType => ActionInput` ([below](#choosing-the-media-types-an-action-takes)) |
| `summary` / `description` | the operation's OpenAPI `summary` and `description`; omitted, the generated ones stay |
| `example` | the operation's OpenAPI example: an `OperationExample` replaces the parts it names, `false` emits none, `null` (default) keeps the generated one ([below](#actions-in-the-openapi-document)) |
| `readOnly` / `destructive` / `idempotent` | how safe the action is to call, projected as the operation's `x-safety`; each `null` (default) stays undeclared ([below](#operation-ids-safety-and-extensions)) |
| `extensions` | OpenAPI vendor extensions for the action's operation, keyed by `x-` name, merged over the mount type's `extensions` |
| `scopes` | the [OAuth scopes](scopes.md) every method of the action needs; `[]` for none, `null` (default) asks the scope resolver |

> [!NOTE]
> `asLink` visibility matches invocation **exactly**: `Authorizer::allowsAction()` (which
> decides whether to render the link) simply wraps `authorizeAction()` (which enforces it) in
> a try/catch, so both pass when the action declares no ability. A declared ability that no
> policy or `Gate::define()` answers denies both the call and the link. A client never sees a link to an action it could
> not invoke, and never loses a link to one it could.

An `asLink` link's href sits on the same base as the resource's `self` link. That's the
server's `base_uri` when one is configured, or the request host plus the server's route
prefix when it's empty.

### Showing an action link only in some states

`asLink` renders the link whenever the ability passes. A link that belongs only to some
states, such as completing an upload only while the file is pending, shouldn't move that
state into the ability. The gate would then answer an out-of-state call with a plain `403`
instead of the handler's own error. Declare the link on the resource instead, with core's
`ActionLink`, next to its [declared links](resources.md#declared-links):

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Link\ActionLink;

final class AlbumResource extends AbstractResource
{
    public function links(): array
    {
        return [
            ActionLink::for('download')
                ->when(static fn(Album $album, JsonApiRequestInterface $request): bool => $album->status === AlbumStatus::Released->value)
                ->describedAs('A short-lived link to the album as CSV, present while the album is released.'),
        ];
    }
}
```

`ActionLink::for()` names a resource-scope action of the resource's own type. The package
still builds the href, the method and the `describedby` from the action, on the server's
link base. It checks the action's `ability` first, then a
[`ConditionallyLinked`](soft-deletes.md#discoverable-restore-link) handler's
`shouldLink()`, then `when()` with the rendered model and the request, and renders the link
only when all of them pass. `when()` is optional. Prefer it to `ConditionallyLinked` when
the condition belongs to the resource, or needs the request.

Declaring an `ActionLink` implies the link, so the action needs no `asLink: true`. When it
has one anyway, the `ActionLink`'s condition and description apply. `describedAs()`
replaces the generic description of the link in `<Type>ResourceLinks`; the rest of the
schema is unchanged and the condition isn't described.

A resource served on several servers may name an action only some of them expose. A
server without the action neither renders the link nor lists it in its document.
`jsonapi:optimize` fails on these, naming the resource class:

- an `ActionLink` for a path that no server serving the type has a resource-scope action
  for, which is usually a typo;
- a declared link named like an `ActionLink`'s path;
- two `ActionLink`s for one path.

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

## A download action: answering with a URL

An action that hands out a URL, such as a short-lived presigned download link, declares
`SeeOther` with the media type the URL serves. Use `application/octet-stream` when the type
isn't known. The handler returns the URL with `$context->seeOther()` or
`SeeOtherResponse::to()`, and an optional expiry:

```php
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use Illuminate\Support\Facades\Date;

#[AsJsonApiAction(
    type: 'albums',
    path: 'download',
    methods: ['GET'],
    responds: [new SeeOther(description: 'A short-lived link to the album as CSV.', mediaType: 'text/csv')],
    readOnly: true,
)]
final readonly class DownloadAlbum implements ActionHandlerInterface
{
    public function __construct(private AlbumExports $exports) {}

    public function handle(ActionContext $context): SeeOtherResponse
    {
        return $context->seeOther($this->exports->presignedUrl($context->entity()), Date::now()->addMinutes(5));
    }
}
```

The request's `Accept` decides what the client gets. A request naming
`application/vnd.api+json`, with nothing it lists weighing more, gets a `200` document
carrying the URL as `links.location`, with its `type` and `meta.expiresAt`. A script calling
`fetch()` needs that, since it can't read a `303`'s `Location`. Any other request gets the
`303` to the URL: a link click, a browser's address bar, `curl`, `*/*` or no `Accept` at
all. So the `download` link on each released album, which the album resource declares with
an [`ActionLink`](#showing-an-action-link-only-in-some-states), downloads the file when a
user clicks it. The action
never answers `406`, because it can always send its `303`. Both shapes carry
`Cache-Control: no-store` and `Vary: Accept` unless the handler sets its own, and errors stay
JSON:API documents.

The `mediaType` is the opt-in. A bare `new SeeOther()`, such as an async completion, stays a
plain `303` for every client, and an `Accept` it can't answer is still a `406`. The OpenAPI
document describes both responses of a download action: the `200` location document and the
`303` with its `Location` header. Generator contract 17 introduced this, and every document
now carries the baseline of 18. Core's
[location responses](https://github.com/haddowg/json-api/blob/main/docs/responses.md#location-responses)
covers the rest, including `withType()` for a file whose type varies.

## `ActionContext`

The handler's single argument carries everything resolved for the call. Its shape drives the
OpenAPI projection so the generated document matches what the handler returns.

| Method | Returns |
| --- | --- |
| `entity()` | the resolved entity at resource scope, `null` at collection scope |
| `input()` | the hydrated input object for a `Document` body, otherwise `null` |
| `request()` | the PSR-7 `JsonApiRequestInterface`; a `Raw` body and uploaded files are read off it with `getBody()` / `getUploadedFiles()` |
| `requestMediaType()` | the media type the body was sent as, lowercased and without parameters, or `null` when the request declared none |
| `responseMediaType()` | the media type negotiated from `Accept` for the response ([raw responses](#a-raw-response-csv-pdf-and-other-bodies)) |
| `queryParameters()` | core's parsed `QueryParameters` for the request |
| `server()` | the resolving core server the request was dispatched to |
| `descriptor()` | the action's resolved declaration |
| `serializer()` | the serializer for the action's response type (the mount type unless `responds` names another) |

And the response builders:

| Builder | Response |
| --- | --- |
| `data($entityOrList)` | a `200` resource or collection document, rendered through `serializer()` |
| `meta([...])` | a `200` meta-only document |
| `noContent()` | a bodyless `204` |
| `accepted($pollUrl, $job = null)` | a `202` with `Content-Location`, optionally rendering the job ([async writes](async.md)) |
| `seeOther($url, $expiresAt = null)` | a `303`, or a location document for a `SeeOther` declared with a `mediaType` ([above](#a-download-action-answering-with-a-url)) |
| `raw($body, $contentType = null, $status = 200, $headers = [])` | a non-JSON:API body ([above](#a-raw-response-csv-pdf-and-other-bodies)) |

A handler may also return core's `ErrorResponse` to render a JSON:API error document.

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

The operation also gets an example request and response, built from the declared examples of
the input and response types' fields. `example` replaces or removes it:

```php
#[AsJsonApiAction(
    type: 'albums',
    path: 'summary',
    scope: ActionScope::Collection,
    responds: [new MetaResult()],
    example: new OperationExample(responses: [200 => ['meta' => ['albums' => 12]]]),
)]
```

See [operation examples](openapi.md#operation-examples).

### Operation ids, safety and extensions

An action's operation id is `action.<type>.<path>`. An action that answers to more than one
method gets one id per method, with the lower-case method appended, so an action declared
with `methods: ['GET', 'POST']` projects `action.albums.preview.get` and
`action.albums.preview.post`. Both methods share one route, so the route name and any `asLink`
link stay the same.

`readOnly`, `destructive` and `idempotent` tell a client whether a call changes anything,
whether it can lose data, and whether repeating it is harmless. The ones you declare land on
the operation as one `x-safety` object, and an action that declares none gets no `x-safety`:

```php
#[AsJsonApiAction(
    type: 'albums',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    readOnly: true,
)]
```

`extensions` adds vendor extensions to the action's operation only. They merge over the
mount type's `#[AsJsonApiResource(extensions: ...)]`, and the action's value wins on a shared
key. Keys need the `x-` prefix, and `x-oai-` and `x-oas-` are reserved, the same rules as the
type-level `extensions`.

Discovery checks every type's actions, per server, so a declaration that would produce a
broken document stops the application booting, and `jsonapi:optimize` refuses to cache it.
The error names the handler class. Discovery rejects:

- one path declared at both the resource and the collection scope, since both would get the
  same operation id;
- a path that reads as one of the type's other operation-id words once case and punctuation
  are dropped, such as `fetch-one` or `update`;
- two actions on one type whose ids differ only in case or punctuation, such as `reindex`
  and `re-index`;
- an action declared both `readOnly` and `destructive`.

The synthesized soft-delete `force-delete` action declares itself destructive.

See [action safety](openapi.md#action-safety-x-safety) for how the document carries these.
