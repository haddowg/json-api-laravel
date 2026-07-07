<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Action;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A discovery-scan guard fixture: the `handle()` return type is narrowed to exactly
 * {@see MetaResponse} AND the attribute declares the matching `new MetaResult()` response, so
 * the declared body shape agrees with the projected shape — discovery accepts it.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'meta-with-flag', responds: [new MetaResult()])]
final class MetaHandlerWithOutputMeta implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        throw new \LogicException('Never invoked — this fixture only exercises discovery classification.');
    }
}
