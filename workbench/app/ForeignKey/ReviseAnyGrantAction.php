<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * The collection twin of {@see ReviseGrantAction}: it takes an `fk-revisable-grants`
 * document by `PATCH` with no grant to compare its input with, and answers with the locked
 * secret ids the input holds.
 */
#[AsJsonApiAction(type: 'fk-revisable-grants', path: 'revise-any', methods: ['PATCH'], scope: ActionScope::Collection, input: ActionInput::Document, inputType: 'fk-revisable-grants', responds: [new MetaResult()])]
final class ReviseAnyGrantAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input instanceof GrantInterface);

        return $context->meta(['received' => GrantInputIds::of($input, 'lockedSecrets')]);
    }
}
