<?php

declare(strict_types=1);

namespace Workbench\App\Extensions;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /gadgets/{id}/-actions/inspect`: a custom action, which takes the type-wide
 * extensions but no per-operation set.
 */
#[AsJsonApiAction(type: 'gadgets', path: 'inspect', responds: [new MetaResult()])]
final readonly class InspectGadgetAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['inspected' => true]);
    }
}
