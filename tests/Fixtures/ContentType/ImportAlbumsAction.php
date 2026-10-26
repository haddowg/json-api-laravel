<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ContentType;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes an album either as a JSON:API document or as a CSV line, and reports which it got.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'import',
    scope: ActionScope::Collection,
    responds: [new MetaResult()],
    accepts: ['application/vnd.api+json' => ActionInput::Document, 'text/csv' => ActionInput::Raw],
)]
final class ImportAlbumsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();

        return $context->meta([
            'mediaType' => $context->requestMediaType(),
            'title' => $input !== null ? Accessor::get($input, 'title') : \explode(',', (string) $context->request()->getBody())[0],
        ]);
    }
}
