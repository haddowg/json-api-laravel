<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-teams` document by `PATCH` and answers with the members it names, read from
 * the request. The in-memory team has no property for its foreign-key `members`, so its
 * input doesn't hold them.
 */
#[AsJsonApiAction(type: 'fk-teams', path: 'regroup', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-teams', responds: [new MetaResult()])]
final class RegroupTeamAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $members = [];
        foreach ($context->request()->getToManyRelationship('members')->resourceIdentifiers as $identifier) {
            $members[] = (string) $identifier->id;
        }

        return $context->meta(['members' => $members]);
    }
}
