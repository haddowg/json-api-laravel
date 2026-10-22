<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\InputMiddleware;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A no-input action: whatever body arrives is never read.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'echo-none', scope: ActionScope::Collection, responds: [new MetaResult()])]
final class EchoNoInputAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['handled' => true]);
    }
}
