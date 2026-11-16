<?php

declare(strict_types=1);

namespace Workbench\App\GateOrder;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * An action only an admin may call.
 */
#[AsJsonApiAction(type: 'gateWidgets', path: 'poke', responds: [new NoContent()], ability: 'poke')]
final readonly class PokeGateWidget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        GateLog::$entries[] = 'poke';

        return $context->noContent();
    }
}
