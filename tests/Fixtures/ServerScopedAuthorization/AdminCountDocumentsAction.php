<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A `documents` action on the `admin` server that declares no tags of its own, so it
 * takes the tags of the class that server exposes `documents` through.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'documents', path: 'count', methods: ['GET'], scope: ActionScope::Collection, responds: [new MetaResult()], server: 'admin')]
final class AdminCountDocumentsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return MetaResponse::fromMeta(['count' => 1]);
    }
}
