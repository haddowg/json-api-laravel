<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * `POST /npCrates/-actions/roll` answers with every crate, in a collection it builds itself.
 */
#[AsJsonApiAction(type: 'npCrates', path: 'roll', scope: ActionScope::Collection)]
final class NpRollCrates implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $crates = [];
        foreach (\array_keys(NestedPivotServiceProvider::CRATES) as $n) {
            $crate = $this->providers->forType('npCrates')->fetchOne('npCrates', NpKeys::wire('c', $n));
            \assert($crate !== null);
            $crates[] = $crate;
        }

        return DataResponse::fromCollection($crates, $context->serializer());
    }
}
