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
 * Takes an `fk-users` document by `PATCH`, so a `lockedItems` it names is compared with the
 * items the user holds through their foreign key, and changes nothing.
 */
#[AsJsonApiAction(type: 'fk-users', path: 'revise', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-users', responds: [new MetaResult()])]
final class ReviseLockedItemsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['revised' => true]);
    }
}
