<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * {@see DownloadAlbum} on the `admin` server. The album resource links the download on
 * every server it is served on, so each of them exposes the action. The byte-compat twin
 * of the Symfony example's `AdminDownloadAlbum`.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'download',
    methods: ['GET'],
    responds: [new SeeOther(description: 'A short-lived link to the album as CSV.', mediaType: 'text/csv')],
    server: 'admin',
    readOnly: true,
)]
final readonly class AdminDownloadAlbum implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        return (new DownloadAlbum())->handle($context);
    }
}
