<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedConversions;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionInputFactoryInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes a `journals` document by PATCH and answers with the name its input holds and the
 * moods the hook recorded.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'journals',
    path: 'revise',
    methods: ['PATCH'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'journals',
    responds: [new MetaResult()],
)]
final class ReviseJournal implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new JournalInput();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input instanceof JournalInput);

        return $context->meta(['name' => $input->name, 'moods' => JournalResource::$moods]);
    }
}
