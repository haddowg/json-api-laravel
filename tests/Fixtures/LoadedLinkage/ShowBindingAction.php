<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Answers with the binding it is invoked on, in a response it builds itself.
 */
#[AsJsonApiAction(type: 'bindings', path: 'show')]
final class ShowBindingAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        return DataResponse::fromResource($context->entity(), $context->serializer());
    }
}
