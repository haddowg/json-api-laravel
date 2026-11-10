<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Answers with the template it is invoked on, through the action context.
 */
#[AsJsonApiAction(type: 'templates', path: 'show')]
final class ShowTemplateAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $template = $context->entity();
        \assert($template !== null);

        return $context->data($template);
    }
}
