<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * Answers with shelf 1 and book 1 in one collection, each rendered as its own type.
 */
#[AsJsonApiAction(type: 'shelves', path: 'mix', scope: ActionScope::Collection)]
final class MixShelvesAction implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $shelf = $this->providers->forType('shelves')->fetchOne('shelves', '1');
        $book = $this->providers->forType('books')->fetchOne('books', '1');
        \assert($shelf !== null && $book !== null);

        $server = $context->server();

        return DataResponse::fromCollection([$shelf, $book], new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $server->serializerFor($object instanceof Book || $object instanceof BookRecord ? 'books' : 'shelves'),
            [$server->serializerFor('shelves'), $server->serializerFor('books')],
        ));
    }
}
