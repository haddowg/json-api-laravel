<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionLinks;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /records/{id}/-actions/feature`, gated by the `featureRecord` ability, so its
 * link needs both the gate and the action link's condition to pass.
 */
#[AsJsonApiAction(type: 'records', path: 'feature', responds: [new NoContent()], ability: 'featureRecord')]
final readonly class FeatureRecord implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
