<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Action;

use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A discovery-scan guard fixture: the `handle()` return type is narrowed to exactly
 * {@see NoContentResponse}, but its `responds` declares no `new NoContent()`. Discovery
 * must fail loudly rather than project a `200` body the handler can never return.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'nocontent-no-flag')]
final class NoContentHandlerMissingReturns204 implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        throw new \LogicException('Never invoked — this fixture only exercises discovery classification.');
    }
}
