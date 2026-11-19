<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-grants` document and answers with the grant it was posted to, holding the
 * secret ids the document sent.
 */
#[AsJsonApiAction(type: 'fk-grants', path: 'stamp', input: ActionInput::Document, inputType: 'fk-grants')]
final class StampGrantAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $grant = $context->entity();
        $input = $context->input();
        \assert($grant instanceof FkGrant && $input instanceof FkGrant);
        $grant->received = $input->received;

        return $context->data($grant);
    }
}
