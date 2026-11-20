<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotMembership;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes a `crates` document by `PATCH`, so the `records` it names are compared with the
 * records the crate holds, and changes nothing.
 */
#[AsJsonApiAction(type: 'crates', path: 'revise', methods: ['PATCH'], input: ActionInput::Document, inputType: 'crates', responds: [new MetaResult()])]
final class ReviseCrateRecords implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['revised' => true]);
    }
}
