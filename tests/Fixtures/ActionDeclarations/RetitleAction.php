<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * A `Document` action that names no `inputType`, which fails discovery.
 *
 * @internal
 */
#[AsJsonApiAction(type: 'guarded', path: 'retitle', input: ActionInput::Document, responds: [new NoContent()])]
final class RetitleAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        throw new \LogicException('Never invoked; this fixture only exercises discovery.');
    }
}
