<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Action;

use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A discovery-scan guard fixture: the `handle()` return type is narrowed to exactly
 * {@see RawResponse}, but its `responds` declares no `new RawResult(...)`.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'raw-no-result')]
final class RawHandlerMissingRawResult implements ActionHandlerInterface
{
    public function handle(ActionContext $context): RawResponse
    {
        throw new \LogicException('Never invoked — this fixture only exercises discovery classification.');
    }
}
