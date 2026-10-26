<?php

declare(strict_types=1);

namespace Workbench\App\Examples;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /widgets/{id}/-actions/rename`, which answers with a widget but switches its example off.
 */
#[AsJsonApiAction(type: 'widgets', path: 'rename', example: false)]
final readonly class RenameWidgetAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        return $context->data($context->entity() ?? new Widget());
    }
}
