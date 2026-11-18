<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionInputFactoryInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-imports` document and answers with the user it was posted to.
 */
#[AsJsonApiAction(type: 'fk-users', path: 'import', input: ActionInput::Document, inputType: 'fk-imports')]
final class ImportSecretsAction implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new FkImport();
    }

    public function handle(ActionContext $context): DataResponse
    {
        $user = $context->entity();
        \assert(\is_object($user));

        return $context->data($user);
    }
}
