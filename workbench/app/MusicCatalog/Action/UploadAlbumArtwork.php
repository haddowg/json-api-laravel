<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry;
use Psr\Http\Message\UploadedFileInterface;

/**
 * `POST /albums/{id}/-actions/artwork` — the **Raw-input** custom action (byte-compat twin
 * of the Symfony example's `UploadAlbumArtwork`): a binary cover-artwork upload, the escape
 * hatch for a non-JSON:API body. Because the upload is not `application/vnd.api+json`, the
 * request content-type negotiation is relaxed (`ActionInput::Raw`) and no JSON:API body
 * parsing runs; the handler reads the raw body / uploaded file straight off the PSR-7
 * request, attaches it to the resolved album, persists, and returns a bodyless `204` — so it
 * declares `returns204: true` (the generated document advertises a `204`, not an albums body).
 */
#[AsJsonApiAction(type: 'albums', path: 'artwork', input: ActionInput::Raw, returns204: true)]
final class UploadAlbumArtwork implements ActionHandlerInterface
{
    public function __construct(private readonly DataPersisterRegistry $persisters) {}

    public function handle(ActionContext $context): NoContentResponse
    {
        $album = $context->entity();
        \assert($album !== null);

        $request = $context->request();

        // Prefer a multipart uploaded file; fall back to the raw request body (a blob POST).
        $artwork = null;
        $files = $request->getUploadedFiles();
        $first = \reset($files);
        if ($first instanceof UploadedFileInterface) {
            $artwork = (string) $first->getStream();
        }

        if ($artwork === null || $artwork === '') {
            $artwork = (string) $request->getBody();
        }

        Accessor::set($album, 'artwork', $artwork);
        $this->persisters->forType('albums')->update('albums', $album);

        return $context->noContent();
    }
}
