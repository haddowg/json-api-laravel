<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters\Invalid;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A resource-scope action cannot take its type's filters.
 */
#[AsJsonApiAction(type: 'gadgets', path: 'polish', responds: new MetaResult(), filters: true)]
final class FilteredResourceAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta([]);
    }
}
