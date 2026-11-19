<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\DeclaresActionRequestMetaInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes a raw body, which carries no JSON:API meta, yet declares request meta.
 */
#[AsJsonApiAction(type: 'tr-plains', path: 'upload', input: ActionInput::Raw, responds: [new NoContent()])]
final class RawUploadNote implements ActionHandlerInterface, DeclaresActionRequestMetaInterface
{
    public function requestDocumentMeta(ActionRequestMetaContext $context): iterable
    {
        return [Str::make('checksum')];
    }

    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
