<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * Answers with a collection of templates it builds the response for itself.
 */
#[AsJsonApiAction(type: 'templates', path: 'list', scope: ActionScope::Collection)]
final class ListTemplatesAction implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $template = $this->providers->forType('templates')->fetchOne('templates', '1');
        \assert($template !== null);

        return DataResponse::fromCollection([$template], $context->serializer());
    }
}
