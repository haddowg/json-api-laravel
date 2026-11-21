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
 * Takes a `tallies` document or a raw CSV body. It answers with the pivot value types the
 * `marks` hook received and the raw body it was sent.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'tallies',
    path: 'import',
    scope: ActionScope::Collection,
    inputType: 'tallies',
    responds: [new MetaResult()],
    accepts: ['application/vnd.api+json' => ActionInput::Document, 'text/csv' => ActionInput::Raw],
)]
final class ImportTallies implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new TallyInput();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta([
            'received' => TallyResource::$received,
            'input' => $context->input() === null ? null : 'document',
            'raw' => $context->input() === null ? (string) $context->request()->getBody() : null,
        ]);
    }
}
