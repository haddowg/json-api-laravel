<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionInputContext;

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
 * Hydrates a `contextNotes` input by `POST` or `PATCH` and answers with what it holds.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'contextNotes',
    path: 'record',
    methods: ['POST', 'PATCH'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'contextNotes',
    responds: [new MetaResult()],
)]
final class RecordContextNoteAction implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new ContextNote();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $note = $context->input();
        \assert($note instanceof ContextNote);

        return $context->meta(['code' => $note->code, 'note' => $note->note]);
    }
}
