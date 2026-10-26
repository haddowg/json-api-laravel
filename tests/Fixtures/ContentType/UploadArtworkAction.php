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
 * A raw-input action that lists no media types, so it takes any but the form types.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'albums', path: 'upload-artwork', scope: ActionScope::Collection, input: ActionInput::Raw, responds: [new MetaResult()])]
final class UploadArtworkAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['mediaType' => $context->requestMediaType(), 'bytes' => \strlen((string) $context->request()->getBody())]);
    }
}
