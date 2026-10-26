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
 * Declares "re-index", whose id flattens to the same name as {@see ReindexAction}.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 're-index', scope: ActionScope::Resource, responds: [new MetaResult()])]
final class HyphenatedReindexAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        throw new \LogicException('Never invoked; this fixture only exercises discovery.');
    }
}
