<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotNumbers;

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
 * Takes a `tallies` document as its input by PATCH and answers with the pivot value types its
 * `marks` hook received.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'tallies',
    path: 'revise',
    methods: ['PATCH'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'tallies',
    responds: [new MetaResult()],
)]
final class ReviseTally implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new TallyInput();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['received' => TallyResource::$received]);
    }
}
