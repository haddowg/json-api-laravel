<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RawResponses;

use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Renders one relic as plain text, on a type that declares deprecation headers.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'relics', path: 'card', methods: ['GET'], responds: [new RawResult('text/plain')])]
final class RelicCardAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): RawResponse
    {
        return $context->raw('relic');
    }
}
