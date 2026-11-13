<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Answers with the shelf it is invoked on.
 */
#[AsJsonApiAction(type: 'shelves', path: 'show', responds: [new ActionResource('shelves')])]
final class ShowShelfAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $shelf = $context->entity();
        \assert($shelf !== null);

        return $context->data($shelf);
    }
}
