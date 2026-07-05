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
`outputMeta: true` declares a meta-only response:

```php
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApi\Response\MetaResponse;

#[AsJsonApiAction(type: 'albums', path: 'summary', scope: ActionScope::Collection, outputMeta: true, tags: ['Catalog'])]
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
`returns204: true` declares a bodyless response:

```php
use haddowg\JsonApi\Response\NoContentResponse;

#[AsJsonApiAction(type: 'albums', path: 'artwork', input: ActionInput::Raw, returns204: true)]
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
| `inputType` / `outputType` | decouple the request/response document type from the mount type |
| `returns204` / `outputMeta` | body-shape declarations (mutually exclusive with each other and `outputType`) |
| `ability` | the Gate ability checked before the handler ([authorization](authorization.md)) |
| `server` / `name` / `tags` | server exposure, route-name override, OpenAPI tags |
| `asLink` | expose the action as an ability-aware `links` member on the mount type's resources (resource scope only) |

> [!NOTE]
> `asLink` visibility matches invocation **exactly**: `Authorizer::allowsAction()` (which
> decides whether to render the link) simply wraps `authorizeAction()` (which enforces it) in
> a try/catch — so both are inert when the action declares no ability and none is registered,
> and both are gated the moment one exists. A client never sees a link to an action it could
> not invoke, and never loses a link to one it could.

## `ActionContext`

The handler's single argument gives you the resolved entity (resource scope), the hydrated
input (Document mode), the request, and the response builders — `$context->data($entity)`,
`$context->meta([...])`, `$context->noContent()`. Its shape drives the OpenAPI projection so
the generated document matches what the handler returns.

## Actions in the OpenAPI document

Each action projects an operation under its mount type's tag (or its own `tags`). A secured
action carries its security requirement; a `returns204`/`outputMeta` action advertises the
corresponding body shape. See [openapi](openapi.md).
