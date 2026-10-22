<?php

declare(strict_types=1);

namespace Workbench\App\Timezone;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * An `admin`-only action rendered as a gig link, so a `gigs` resource shows whether it
 * picked up the other server's link contributor.
 */
#[AsJsonApiAction(type: 'gigs', path: 'reschedule', server: 'admin', asLink: true)]
final readonly class RescheduleGig implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $gig = $context->entity();
        \assert($gig !== null);

        return $context->data($gig);
    }
}
