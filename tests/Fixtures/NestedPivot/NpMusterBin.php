<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /npBins/{id}/-actions/muster` answers with the bin it is invoked on.
 */
#[AsJsonApiAction(type: 'npBins', path: 'muster')]
final class NpMusterBin implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $entity = $context->entity();
        \assert($entity !== null);

        return $context->data($entity);
    }
}
