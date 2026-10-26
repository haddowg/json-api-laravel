<?php

declare(strict_types=1);

namespace Workbench\App\ActionSafety;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A read-only action that answers to GET and POST, so each method gets its own operation id.
 */
#[AsJsonApiAction(
    type: 'lamps',
    path: 'inspect',
    methods: ['GET', 'POST'],
    responds: [new MetaResult()],
    readOnly: true,
    idempotent: true,
)]
final readonly class InspectLampAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['method' => $context->request()->getMethod()]);
    }
}
