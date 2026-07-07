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

> [!NOTE]
> `asLink` visibility matches invocation **exactly**: `Authorizer::allowsAction()` (which
> decides whether to render the link) simply wraps `authorizeAction()` (which enforces it) in
> a try/catch — so both are inert when the action declares no ability and none is registered,
> and both are gated the moment one exists. A client never sees a link to an action it could
> not invoke, and never loses a link to one it could.

> [!NOTE]
> `responds` replaces the former `outputType` / `outputMeta` / `returns204` params. Migrate
> `outputMeta: true` → `responds: [new MetaResult()]`, `returns204: true` → `responds: [new NoContent()]`,
> and `outputType: 'x'` → `responds: [new ActionResource('x')]`. An **asynchronous** action
> advertises `responds: [new Accepted('job-type'), new SeeOther()]` — see [async writes](async.md).

## `ActionContext`

The handler's single argument gives you the resolved entity (resource scope), the hydrated
input (Document mode), the request, and the response builders — `$context->data($entity)`,
`$context->meta([...])`, `$context->noContent()`. Its shape drives the OpenAPI projection so
the generated document matches what the handler returns.

## Actions in the OpenAPI document

Each action projects an operation under its mount type's tag (or its own `tags`). A secured
action carries its security requirement; its `responds` set becomes the advertised success
responses (a `200` document or meta, `204`, `202`, `303`). See [openapi](openapi.md).
