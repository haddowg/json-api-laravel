<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * An action path that flattens to the reserved "fetchOne" operation word.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'fetch-one', scope: ActionScope::Resource, responds: [new MetaResult()])]
final class FetchOneAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        throw new \LogicException('Never invoked; this fixture only exercises discovery.');
    }
}
