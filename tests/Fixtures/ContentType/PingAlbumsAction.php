<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ContentType;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A no-input action.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'ping', scope: ActionScope::Collection, responds: [new MetaResult()])]
final class PingAlbumsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['pong' => true]);
    }
}
