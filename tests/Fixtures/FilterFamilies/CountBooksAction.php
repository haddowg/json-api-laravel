<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Takes every filter `books` declares and answers with the ids it reads.
 */
#[AsJsonApiAction(type: 'books', path: 'count', methods: ['GET'], scope: ActionScope::Collection, responds: new MetaResult(), filters: true)]
final class CountBooksAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $serializer = $context->serializer();

        $ids = [];
        foreach ($context->filtered()->each(2) as $book) {
            $ids[] = $serializer->getId($book);
        }

        return $context->meta(['ids' => $ids]);
    }
}
