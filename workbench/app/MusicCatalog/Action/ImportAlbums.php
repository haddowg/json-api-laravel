<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use Workbench\App\MusicCatalog\OpenApi\AlbumImportRows;

/**
 * `POST /albums/-actions/import` takes an import in whichever form the client has: a CSV
 * export, a scanned sleeve, a multipart form, or JSON rows. Each accepted type is its own
 * entry in the OpenAPI request body. The example only reports what it received. Byte-compat
 * twin of the Symfony example's `ImportAlbums`.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'import',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    responds: [new MetaResult()],
    tags: ['Catalog'],
    summary: 'Import albums from a file',
    description: 'Creates albums from a CSV export, a scanned sleeve image, a multipart form upload or a JSON list of rows. The response reports the media type the server read.',
    accepts: [
        'text/csv',
        'image/*',
        'multipart/form-data',
        new AcceptedBody('application/json', schema: AlbumImportRows::class),
    ],
)]
final class ImportAlbums implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta([
            'import' => [
                'mediaType' => $context->requestMediaType(),
                'bytes' => \strlen((string) $context->request()->getBody()),
            ],
        ]);
    }
}
