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
 * Declares "export" at the resource scope, beside {@see ExportAllAction} at the collection scope.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'export', scope: ActionScope::Resource, responds: [new MetaResult()])]
final class ExportOneAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        throw new \LogicException('Never invoked; this fixture only exercises discovery.');
    }
}
