<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Action;

use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A discovery-scan guard fixture: the `handle()` return type is narrowed to exactly
 * {@see MetaResponse}, but the attribute does NOT declare `outputMeta`. Discovery must
 * fail loudly rather than project a resource-body response the handler can never return.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'meta-no-flag')]
final class MetaHandlerMissingOutputMeta implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        throw new \LogicException('Never invoked — this fixture only exercises discovery classification.');
    }
}
