<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * {@see ReviseAnyGrantAction} called on a user: its input reads as a grant, not the resource
 * it was called on, so there is no stored grant to compare it with.
 */
#[AsJsonApiAction(type: 'fk-users', path: 'revise-grant', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-revisable-grants', responds: [new MetaResult()])]
final class ReviseGrantFromUserAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return (new ReviseAnyGrantAction())->handle($context);
    }
}
