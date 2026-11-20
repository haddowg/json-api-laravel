<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\RequestMeta;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApi\Resource\ResourceMeta;
use haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Action\ActionInput;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;

/**
 * The declared `meta` of a write: the top-level `meta` a type's
 * {@see \haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface::requestDocumentMeta()}
 * declares per write, the resource object `meta` its
 * {@see DeclaresResourceMetaInterface::resourceMeta()} declares per direction, and an action
 * handler's request `meta`. Read from the resource, or a composed type's hydrator or
 * serializer, once per server, type and context.
 *
 * The {@see CrudOperationHandler} and the action invoker validate each write against it and
 * put the typed values on the request, and route registration and `jsonapi:optimize` read
 * every declaration through {@see assertDeclared()} so a misdeclaration fails the boot.
 */
final class RequestMetaResolver
{
    /**
     * The request attribute marking a sub-request as one operation of an Atomic Operations
     * batch, whose declarations are read with the atomic flag set.
     */
    public const string ATOMIC_ATTRIBUTE = MetaContext::ATOMIC_ATTRIBUTE;

    /** @var array<string, MetaShape> */
    private array $declared = [];

    public function __construct(
        private readonly TypeMetadataResolver $types = new TypeMetadataResolver(),
    ) {}

    /**
     * The members `$type` declares for the top-level `meta` of the write `$context` names.
     *
     * @throws \haddowg\JsonApi\Resource\MetaMisdeclared
     */
    public function forWrite(Server $server, string $type, RequestMetaContext $context): MetaShape
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0request\0" . $context->describe();

        return $this->declared[$key] ??= RequestMeta::forOperation($this->hydrator($server, $type) ?? new \stdClass(), $context, $type);
    }

    /**
     * The members `$type` declares for its resource objects' `meta` in `$context`'s
     * direction: read from the serializer, or for a write from the hydrator when it declares
     * them, the resource serving both when the type has one.
     *
     * @throws \haddowg\JsonApi\Resource\MetaMisdeclared
     */
    public function forResource(Server $server, string $type, MetaContext $context): MetaShape
    {
        $direction = $context->isRead() ? 'read' : ($context->creating() ? 'create' : 'update') . ($context->isAtomic() ? ':atomic' : '');
        $key = \spl_object_id($server) . "\0" . $type . "\0resource\0" . $direction;

        return $this->declared[$key] ??= ResourceMeta::forType(
            self::resourceMetaDeclarer($context, $this->hydrator($server, $type), $this->serializer($server, $type)) ?? new \stdClass(),
            $context,
            $type,
        );
    }

    /**
     * The members an action's handler declares for the top-level `meta` of a call with
     * `$method`.
     *
     * @throws \haddowg\JsonApi\Resource\MetaMisdeclared
     */
    public function forAction(object $handler, string $method, string $owner): MetaShape
    {
        $key = \spl_object_id($handler) . "\0action\0" . \strtoupper($method);

        return $this->declared[$key] ??= RequestMeta::forAction($handler, new ActionRequestMetaContext($method), $owner);
    }

    /**
     * The top-level `meta` of a request, or `null` when its body carries none. A request
     * with an empty body has none, so a bodiless call is never refused for its body.
     */
    public static function documentMetaOf(JsonApiRequestInterface $request): mixed
    {
        $parsed = $request->getParsedBody();
        if (!\is_array($parsed) && (string) $request->getBody() === '') {
            return null;
        }

        return \is_array($parsed) && \array_key_exists('meta', $parsed) ? $parsed['meta'] : null;
    }

    /**
     * The `data.meta` of a write's resource object, or `null` when it sends none.
     */
    public static function resourceMetaOf(JsonApiRequestInterface $request): mixed
    {
        $data = $request->getResource();

        return \is_array($data) && \array_key_exists('meta', $data) ? $data['meta'] : null;
    }

    /**
     * Whether the request is one operation of an Atomic Operations batch.
     */
    public static function isAtomic(JsonApiRequestInterface $request): bool
    {
        return $request->getAttribute(self::ATOMIC_ATTRIBUTE) === true;
    }

    /**
     * Reads every request, resource object, relationship write and `202` meta a type
     * declares, standalone and inside an Atomic Operations batch, so a member declared twice,
     * a member named `*` or two `additionalProperties` entries fail. A resource overriding
     * the deprecated `getMeta()` while declaring its read `resourceMeta()` is refused too,
     * since the declaration covers the object `getMeta()` would add to.
     *
     * @param object|null                $hydrator   the type's resource or hydrator
     * @param object|null                $serializer the type's resource or serializer
     * @param iterable<RelationInterface> $relations the type's relations
     *
     * @throws \haddowg\JsonApi\Resource\MetaMisdeclared
     * @throws \LogicException                           when a resource overrides getMeta() beside resourceMeta()
     */
    public static function assertDeclared(?object $hydrator, ?object $serializer, string $type, iterable $relations): void
    {
        $relations = \is_array($relations) ? $relations : \iterator_to_array($relations, false);
        foreach ([false, true] as $atomic) {
            foreach ([OperationType::Create, OperationType::Update, OperationType::Delete] as $operation) {
                RequestMeta::forOperation($hydrator ?? new \stdClass(), RequestMetaContext::forOperation($operation, $atomic), $type);
            }
            foreach ([OperationType::Create, OperationType::Update] as $operation) {
                $context = MetaContext::write($operation, $atomic);
                ResourceMeta::forType(self::resourceMetaDeclarer($context, $hydrator, $serializer) ?? new \stdClass(), $context, $type);
            }
            foreach ($relations as $relation) {
                foreach ($relation->isToMany() ? [Mode::Replace, Mode::Add, Mode::Remove] : [Mode::Replace] as $mode) {
                    RequestMeta::forOperation($hydrator ?? new \stdClass(), RequestMetaContext::forRelationship($relation->name(), $mode, $atomic), $type);
                }
            }
        }
        foreach ($relations as $relation) {
            if ($relation instanceof PivotRelationInterface) {
                $relation->pivotMetaShape();
            }
            if ($relation instanceof AbstractRelationValue) {
                $relation->identifierMetaShape();
            }
        }
        foreach ([OperationType::Create, OperationType::Update] as $operation) {
            DocumentMeta::forAccepted($serializer ?? new \stdClass(), $operation, [], $type);
        }
        foreach ($relations as $relation) {
            foreach ($relation->isToMany() ? [OperationType::UpdateRelationship, OperationType::AddRelationship, OperationType::RemoveRelationship] : [OperationType::UpdateRelationship] as $operation) {
                DocumentMeta::forOperation($serializer ?? new \stdClass(), $operation, $type, $relation->name());
            }
        }

        $read = ResourceMeta::forType($serializer ?? new \stdClass(), MetaContext::read(), $type);
        if ($serializer instanceof AbstractResource && !$read->isEmpty()
            && (new \ReflectionMethod($serializer, 'getMeta'))->getDeclaringClass()->getName() !== AbstractResource::class) {
            throw new \LogicException(\sprintf(
                'Resource %s for type "%s" declares resourceMeta() and overrides the deprecated getMeta(). Move what getMeta() returns into resourceMeta(), an AdditionalProperties::make()->extractUsing() entry renders the same members, and remove the getMeta() override.',
                $serializer::class,
                $type,
            ));
        }
    }

    /**
     * Reads the request meta an action's handler declares for each of its methods, so a
     * misdeclaration fails, and refuses request meta on an action taking a raw body, which
     * carries no JSON:API `meta`.
     *
     * @throws \haddowg\JsonApi\Resource\MetaMisdeclared
     * @throws \LogicException                           when a raw-body action declares request meta
     */
    public static function assertActionDeclared(object $handler, ActionDescriptor $action): void
    {
        foreach ($action->methods as $method) {
            $shape = RequestMeta::forAction($handler, new ActionRequestMetaContext($method), self::actionOwner($action->path, $action->type));
            if (!$shape->isEmpty() && $action->input === ActionInput::Raw && $action->accepts === []) {
                throw new \LogicException(\sprintf(
                    'The "%s" action of type "%s" takes a raw body but declares request meta for %s; a raw body carries no JSON:API meta. Declare none, or take a document.',
                    $action->path,
                    $action->type,
                    $method,
                ));
            }
        }
    }

    /**
     * Whether a composed type's serializer or hydrator class declares any typed meta.
     */
    public static function declaresMeta(string $class): bool
    {
        foreach ([
            DeclaresRequestDocumentMetaInterface::class,
            DeclaresResourceMetaInterface::class,
        ] as $interface) {
            if (\is_a($class, $interface, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every relation a resource declares, hidden ones included, or none for a type composed
     * without one.
     *
     * @return list<RelationInterface>
     */
    public static function relationsOf(?object $resource): array
    {
        if (!$resource instanceof AbstractResource) {
            return [];
        }

        $relations = [];
        foreach ($resource->allFields() as $field) {
            if ($field instanceof RelationInterface) {
                $relations[] = $field;
            }
        }

        return $relations;
    }

    /**
     * How a misdeclaration names an action.
     */
    public static function actionOwner(string $path, string $type): string
    {
        return \sprintf('"%s" action of type "%s"', $path, $type);
    }

    /**
     * Who declares a type's resource object meta in `$context`: for a write the hydrator when
     * it declares it, else the serializer.
     */
    private static function resourceMetaDeclarer(MetaContext $context, ?object $hydrator, ?object $serializer): ?object
    {
        return $context->isWrite() && $hydrator instanceof DeclaresResourceMetaInterface ? $hydrator : $serializer;
    }

    private function hydrator(Server $server, string $type): ?object
    {
        return $this->types->resourceFor($server, $type)
            ?? ($server->hasHydratorFor($type) ? $server->hydratorFor($type) : null);
    }

    private function serializer(Server $server, string $type): ?object
    {
        return $this->types->resourceFor($server, $type)
            ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
    }
}
