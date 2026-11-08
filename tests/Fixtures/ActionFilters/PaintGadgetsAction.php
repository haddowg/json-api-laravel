<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes only the `name` and `weightRange` filters, and has its own ability.
 */
#[AsJsonApiAction(type: 'gadgets', path: 'paint', scope: ActionScope::Collection, responds: new MetaResult(), ability: 'paint-gadgets', filters: ['weightRange', 'name'])]
final class PaintGadgetsAction implements ActionHandlerInterface
{
    use ReportsGadgets;
}
