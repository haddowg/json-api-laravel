<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RawResponses;

use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Declares CSV but answers in plain text.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'mislabelled', methods: ['GET'], scope: ActionScope::Collection, responds: [new RawResult('text/csv')])]
final class MislabelledExportAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): RawResponse
    {
        return $context->raw('oops', 'text/plain');
    }
}
