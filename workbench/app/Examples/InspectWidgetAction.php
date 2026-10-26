<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /widgets/{id}/-actions/inspect`, whose response example is declared whole.
 */
#[AsJsonApiAction(
    type: 'widgets',
    path: 'inspect',
    responds: [new MetaResult()],
    example: new OperationExample(responses: [200 => ['meta' => ['inspected' => true]]]),
)]
final readonly class InspectWidgetAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['inspected' => true]);
    }
}
