<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionInputFactoryInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Moves a record to a new imprint, taking the related-only `imprints` type as its request
 * document.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'records', path: 'reimprint', input: ActionInput::Document, inputType: 'imprints')]
final class ReimprintRecordAction implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new Imprint();
    }

    public function handle(ActionContext $context): DataResponse
    {
        $record = $context->entity();
        \assert($record instanceof Record);
        $imprint = $context->input();
        \assert($imprint instanceof Imprint);

        $record->imprint = new Imprint('i9', $imprint->name);

        return $context->data($record);
    }
}
