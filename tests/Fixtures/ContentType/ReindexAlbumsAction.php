<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ContentType;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A raw-input action that takes only CSV, and may be called with no body at all.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'reindex', scope: ActionScope::Collection, input: ActionInput::Raw, responds: [new MetaResult()], accepts: ['text/csv'])]
final class ReindexAlbumsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta([
            'bytes' => \strlen((string) $context->request()->getBody()),
            'input' => $context->input() !== null,
        ]);
    }
}
