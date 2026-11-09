<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * An action mounted on the related-only `imprints` type, which fails discovery.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'imprints', path: 'archive', responds: [new NoContent()])]
final class ArchiveImprintAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
