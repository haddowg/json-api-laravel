<?php

declare(strict_types=1);

namespace Workbench\App\RequiredRelation;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes a `tasks` document under the type's create rules and answers that it took it.
 */
#[AsJsonApiAction(type: 'tasks', path: 'draft', methods: ['POST'], scope: ActionScope::Collection, input: ActionInput::Document, inputType: 'tasks', responds: [new MetaResult()])]
final class DraftTaskAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['drafted' => true]);
    }
}
