<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OperationErrors;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /lockers/{id}/-actions/open`, the one operation that raises {@see DepositRequired}.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'lockers', path: 'open', responds: [new NoContent()], errors: [DepositRequired::class])]
final readonly class OpenLocker implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
