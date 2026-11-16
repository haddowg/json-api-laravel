<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes every filter of the inbox and answers with the ids of the items it reads.
 */
#[AsJsonApiAction(type: 'inbItems', path: 'tally', methods: ['GET'], scope: ActionScope::Collection, responds: new MetaResult(), filters: true)]
final class TallyInboxItems implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $serializer = $context->serializer();

        $ids = [];
        foreach ($context->filtered()->each(10) as $item) {
            $ids[] = $serializer->getId($item);
        }

        return $context->meta(['ids' => $ids]);
    }
}
