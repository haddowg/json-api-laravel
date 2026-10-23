<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\InputMiddleware;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Echoes the attributes a Document-input action hydrated from its `albums` document.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'echo-document', scope: ActionScope::Collection, input: ActionInput::Document, responds: [new MetaResult()])]
final class EchoDocumentAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input !== null);

        return $context->meta([
            'title' => Accessor::get($input, 'title'),
            'status' => Accessor::get($input, 'status'),
        ]);
    }
}
