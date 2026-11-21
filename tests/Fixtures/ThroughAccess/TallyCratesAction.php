<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Lists the ids of the crates its filters keep.
 */
#[AsJsonApiAction(type: 'crates', path: 'tally', scope: ActionScope::Collection, responds: new MetaResult(), filters: true)]
final class TallyCratesAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $ids = [];
        foreach ($context->filtered()->each(10) as $crate) {
            $ids[] = $context->serializer()->getId($crate);
        }

        return $context->meta(['ids' => $ids]);
    }
}
