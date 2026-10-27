<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use Illuminate\Support\Facades\Date;

/**
 * `GET /albums/{id}/-actions/download` hands out a short-lived link to the album as CSV,
 * standing in for a presigned storage URL. A JSON:API client gets it as the `location`
 * link of a `200` document. A browser following the album's `download` link gets a `303`
 * to the file. The byte-compat twin of the Symfony example's `DownloadAlbum`.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'download',
    methods: ['GET'],
    responds: [new SeeOther(description: 'A short-lived link to the album as CSV.', mediaType: 'text/csv')],
    asLink: true,
    readOnly: true,
)]
final readonly class DownloadAlbum implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        $album = $context->entity();
        \assert($album !== null);

        $id = Accessor::get($album, 'id');
        \assert(\is_int($id) || \is_string($id));

        return SeeOtherResponse::to(
            'https://storage.example.com/albums/' . $id . '.csv?signature=example',
            expiresAt: Date::now()->addMinutes(5),
        );
    }
}
