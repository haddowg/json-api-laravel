<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-revisable-grants` document by `PATCH` and answers with the grant it was
 * called on, holding the locked secret ids the document sent.
 */
#[AsJsonApiAction(type: 'fk-revisable-grants', path: 'revise', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-revisable-grants')]
final class ReviseGrantAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $grant = $context->entity();
        $input = $context->input();
        \assert($grant instanceof FkGrant && $input instanceof FkGrant);
        $grant->received = $input->lockedSecrets;

        return $context->data($grant);
    }
}
