<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * `POST /tr-items/-actions/import` reads an item document, its `data.meta` validated and
 * hydrated as a create of an item would be, and answers with the item it built.
 */
#[AsJsonApiAction(type: 'tr-items', path: 'import', scope: ActionScope::Collection, input: ActionInput::Document, inputType: 'tr-items', responds: [new ActionResource('tr-items')])]
final class ImportItem implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $item = $context->input();
        \assert($item !== null);
        if (\in_array(Accessor::get($item, 'id'), [null, ''], true)) {
            Accessor::set($item, 'id', '99');
        }

        return $context->data($item);
    }
}
