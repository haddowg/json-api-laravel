<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Action;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A discovery-scan guard fixture: the `handle()` return type keeps the interface's union
 * (it is not narrowed to a single response class), so it declares no single body shape and
 * the guard does not constrain it — discovery accepts it with no `returns204`/`outputMeta`
 * flag (Document mode by default).
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'union')]
final class UnionReturnHandler implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse|MetaResponse|NoContentResponse|ErrorResponse
    {
        throw new \LogicException('Never invoked — this fixture only exercises discovery classification.');
    }
}
