<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * `POST /npCrates/-actions/mix` answers with crate 1 and bin 2 in one collection, each
 * rendered as its own type. Both types name a pivot relation `records`, with different pivot
 * fields.
 */
#[AsJsonApiAction(type: 'npCrates', path: 'mix', scope: ActionScope::Collection)]
final class NpMixBinsAndCrates implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $crate = $this->providers->forType('npCrates')->fetchOne('npCrates', NpKeys::wire('c', 1));
        $bin = $this->providers->forType('npBins')->fetchOne('npBins', NpKeys::wire('c', 2));
        \assert($crate !== null && $bin !== null);

        $server = $context->server();

        return DataResponse::fromCollection([$crate, $bin], new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $server->serializerFor($object === $bin ? 'npBins' : 'npCrates'),
            [$server->serializerFor('npCrates'), $server->serializerFor('npBins')],
        ));
    }
}
