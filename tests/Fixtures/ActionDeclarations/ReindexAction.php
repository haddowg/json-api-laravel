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
 * Declares "reindex", beside {@see HyphenatedReindexAction} whose id flattens to the same name.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'reindex', scope: ActionScope::Resource, responds: [new MetaResult()])]
final class ReindexAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        throw new \LogicException('Never invoked; this fixture only exercises discovery.');
    }
}
