<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\Invalid;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Lists a filter `gadgets` does not declare.
 */
#[AsJsonApiAction(type: 'gadgets', path: 'scrap', scope: ActionScope::Collection, responds: new MetaResult(), filters: ['name', 'shape'])]
final class UndeclaredFilterAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta([]);
    }
}
