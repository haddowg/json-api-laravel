<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * Answers with template 1 and binding 1 in one collection, each rendered as its own type.
 */
#[AsJsonApiAction(type: 'templates', path: 'mixed', scope: ActionScope::Collection)]
final class ListMixedAction implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $template = $this->providers->forType('templates')->fetchOne('templates', '1');
        $binding = $this->providers->forType('bindings')->fetchOne('bindings', '1');
        \assert($template !== null && $binding !== null);

        $server = $context->server();

        return DataResponse::fromCollection([$template, $binding], new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $server->serializerFor($object instanceof Binding || $object instanceof BindingRecord ? 'bindings' : 'templates'),
            [$server->serializerFor('templates'), $server->serializerFor('bindings')],
        ));
    }
}
