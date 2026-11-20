<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionInputFactoryInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-revisable-grants` document by `PATCH` into a {@see GrantCommand} it builds
 * itself, and answers with the locked secret ids it holds. They aren't compared with
 * anything, since the command is no stored grant.
 */
#[AsJsonApiAction(type: 'fk-revisable-grants', path: 'revise-command', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-revisable-grants', responds: [new MetaResult()])]
final class ReviseGrantByCommandAction implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new GrantCommand();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input instanceof GrantCommand);

        return $context->meta(['received' => $input->lockedSecrets]);
    }
}
