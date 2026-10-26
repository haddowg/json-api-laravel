<?php

declare(strict_types=1);

namespace Workbench\App\ActionSafety;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * An action that declares no safety and no extensions of its own.
 */
#[AsJsonApiAction(type: 'lamps', path: 'toggle')]
final readonly class ToggleLampAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        return $context->data($context->entity() ?? new Lamp());
    }
}
