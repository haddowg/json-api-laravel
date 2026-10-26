<?php

declare(strict_types=1);

namespace Workbench\App\ActionSafety;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A destructive collection action with its own vendor extensions, one of which overrides a
 * type-wide key.
 */
#[AsJsonApiAction(
    type: 'lamps',
    path: 'reset',
    scope: ActionScope::Collection,
    responds: [new NoContent()],
    destructive: true,
    idempotent: true,
    extensions: ['x-stability' => 'stable', 'x-workflows' => ['reset-lamps']],
)]
final readonly class ResetLampsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
