<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /npCrates/{id}/-actions/queue` answers `202 Accepted` with the crate as its job
 * resource.
 */
#[AsJsonApiAction(type: 'npCrates', path: 'queue', responds: [new Accepted('npCrates')])]
final class NpQueueCrate implements ActionHandlerInterface
{
    public function handle(ActionContext $context): AcceptedResponse
    {
        $crate = $context->entity();
        \assert($crate !== null);

        return $context->accepted('https://example.test/npCrates/' . $context->server()->serializerFor('npCrates')->getId($crate), $crate);
    }
}
