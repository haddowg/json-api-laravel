<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes every filter `gadgets` declares.
 */
#[AsJsonApiAction(type: 'gadgets', path: 'count', methods: ['GET'], scope: ActionScope::Collection, responds: new MetaResult(), filters: true)]
final class CountGadgetsAction implements ActionHandlerInterface
{
    use ReportsGadgets;
}
