<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RelationshipMetaContext;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataProvider\Polymorphism;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\OpenApi\Metadata\DeclaredOperationResponse;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;

/**
 * The top-level meta an operation's document declares: the type's
 * {@see \haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface::documentMeta()} members
 * for a CRUD operation, the related type's for a related endpoint, and the declared
 * response's for a custom action. Read from the resource, or a composed type's serializer,
 * once per server, type and operation.
 *
 * The {@see CrudOperationHandler} fills the members an `extractUsing()` hook supplies, the
 * controller and the Atomic Operations backend check a response against it in debug mode,
 * and route registration reads every declaration so a misdeclaration fails the boot.
 */
final class DocumentMetaResolver
{
    /** @var array<string, DocumentMeta> */
    private array $declared = [];

    public function __construct(
        private readonly TypeMetadataResolver $types = new TypeMetadataResolver(),
        // The declared success responses, which say whether a delete answers a meta-only 200.
        private readonly ?Discovery $discovery = null,
    ) {}

    /**
     * Whether rendered documents are checked against their declarations, which only debug
     * mode does.
     */
    public function checks(): bool
    {
        return (bool) config('app.debug', false);
    }

    /**
     * The members of the document `$operation` answers with on `$serverName`. Given the
     * response's `$status`, a status that does not carry that document, such as a create's
     * `202`, has none, and an action's `201` is its
     * {@see \haddowg\JsonApi\OpenApi\Metadata\ActionCreated} while any other status is its
     * {@see \haddowg\JsonApi\OpenApi\Metadata\ActionResource} or
     * {@see \haddowg\JsonApi\OpenApi\Metadata\MetaResult}.
     */
    public function forOperation(Server $server, string $serverName, JsonApiOperationInterface $operation, ?int $status = null): DocumentMeta
    {
        [$type, $kind, $documentStatus] = match (true) {
            $operation instanceof FetchResourceOperation => [$operation->target()->type, $operation->target()->id === null ? OperationType::FetchCollection : OperationType::FetchOne, 200],
            $operation instanceof CreateResourceOperation => [$operation->target()->type, OperationType::Create, 201],
            $operation instanceof UpdateResourceOperation => [$operation->target()->type, OperationType::Update, 200],
            default => [null, null, 200],
        };
        // A write accepted for later processing answers its job's document.
        if ($status === 202 && $type !== null && ($kind === OperationType::Create || $kind === OperationType::Update)) {
            return $this->forAccepted($server, $type, $kind);
        }
        if ($status !== null && $status !== $documentStatus && !$operation instanceof CustomActionOperation && !$operation instanceof DeleteResourceOperation) {
            return new DocumentMeta();
        }

        return match (true) {
            $type !== null && $kind !== null => $this->forType($server, $type, $kind),
            $operation instanceof FetchRelatedOperation => $this->forRelation(
                $server,
                $this->types->relationNamed($server, $operation->target()->type, (string) $operation->target()->relationship),
            ),
            $operation instanceof CustomActionOperation => DocumentMeta::forActionStatus(
                $operation->responds(),
                $status ?? 200,
                \sprintf('the "%s" action of type "%s"', $operation->action(), $operation->target()->type),
            ),
            $operation instanceof DeleteResourceOperation => $status !== null && $status !== 200
                ? new DocumentMeta()
                : $this->forDelete($server, $serverName, $operation->target()->type),
            $operation instanceof UpdateRelationshipOperation, $operation instanceof AddToRelationshipOperation, $operation instanceof RemoveFromRelationshipOperation => $this->forRelationshipWrite(
                $server,
                $operation->target()->type,
                new RelationshipMetaContext(
                    (string) $operation->target()->relationship,
                    match (true) {
                        $operation instanceof AddToRelationshipOperation => Mode::Add,
                        $operation instanceof RemoveFromRelationshipOperation => Mode::Remove,
                        default => Mode::Replace,
                    },
                    $operation->context()->httpRequest()?->getAttribute(RequestMetaResolver::ATOMIC_ATTRIBUTE) === true,
                ),
            ),
            default => new DocumentMeta(),
        };
    }

    /**
     * The members `$type` declares for `$operation`.
     *
     * @throws \haddowg\JsonApi\Resource\DocumentMetaMisdeclared
     */
    public function forType(Server $server, string $type, OperationType $operation): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0" . $operation->value;

        if (!isset($this->declared[$key])) {
            $declarer = $this->types->resourceFor($server, $type)
                ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
            $this->declared[$key] = $declarer === null
                ? new DocumentMeta([], $operation === OperationType::FetchCollection)
                : DocumentMeta::forOperation($declarer, $operation, $type);
        }

        return $this->declared[$key];
    }

    /**
     * The members a related endpoint serves: the related type's `FetchCollection` members
     * for a to-many and its `FetchOne` members for a to-one. A polymorphic relation serves
     * no single type, so none.
     */
    public function forRelation(Server $server, ?RelationInterface $relation): DocumentMeta
    {
        if ($relation === null || !Polymorphism::isMonomorphic($relation)) {
            return new DocumentMeta();
        }

        return $this->forType($server, $relation->relatedTypes()[0], $relation->isToMany() ? OperationType::FetchCollection : OperationType::FetchOne);
    }

    /**
     * The members of the meta-only `200` a delete of `$type` answers with: none unless the
     * type declares that status, else its `Delete` members.
     *
     * @throws \haddowg\JsonApi\Resource\DocumentMetaMisdeclared
     */
    public function forDelete(Server $server, string $serverName, string $type): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $serverName . "\0" . $type . "\0delete";
        if (!isset($this->declared[$key])) {
            $this->declared[$key] = DocumentMeta::forDelete(
                $this->forType($server, $type, OperationType::Delete),
                self::deleteResponses($this->discovery, $serverName, $type),
                $type,
            );
        }

        return $this->declared[$key];
    }

    /**
     * The members of the meta-only `200` the relationship write `$context` names answers
     * with, which the parent type `$type` declares. Empty when it declares none, so the
     * write answers its linkage.
     *
     * @throws \haddowg\JsonApi\Resource\DocumentMetaMisdeclared
     */
    public function forRelationshipWrite(Server $server, string $type, RelationshipMetaContext $context): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0relationship\0" . $context->describe();

        return $this->declared[$key] ??= DocumentMeta::forRelationship($this->serializer($server, $type) ?? new \stdClass(), $context, $type);
    }

    /**
     * The members of the job document a create's or an update's `202` answers with, which
     * `$type` declares through `acceptedDocumentMeta()`.
     *
     * @throws \haddowg\JsonApi\Resource\DocumentMetaMisdeclared
     */
    public function forAccepted(Server $server, string $type, OperationType $operation): DocumentMeta
    {
        $key = \spl_object_id($server) . "\0" . $type . "\0accepted\0" . $operation->value;

        return $this->declared[$key] ??= DocumentMeta::forAccepted($this->serializer($server, $type) ?? new \stdClass(), $operation, [], $type);
    }

    private function serializer(Server $server, string $type): ?object
    {
        return $this->types->resourceFor($server, $type)
            ?? ($server->hasSerializerFor($type) ? $server->serializerFor($type) : null);
    }

    /**
     * Reads every top-level document meta `$declarer`, the resource or a composed type's
     * serializer, declares for `$type`, so a member declared twice, a collection member
     * named after one core writes, or a delete's members declared in two places, fails.
     *
     * @param iterable<\haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface> $deleteResponses the delete's declared success responses
     *
     * @throws \haddowg\JsonApi\Resource\DocumentMetaMisdeclared
     */
    public static function assertDeclared(object $declarer, string $type, iterable $deleteResponses): void
    {
        foreach ([OperationType::FetchCollection, OperationType::FetchOne, OperationType::Create, OperationType::Update] as $operation) {
            DocumentMeta::forOperation($declarer, $operation, $type);
        }
        DocumentMeta::forDelete($declarer, $deleteResponses, $type);
    }

    /**
     * The success responses a delete of `$type` declares on `$serverName`.
     *
     * @return list<DeclaredOperationResponse>
     */
    public static function deleteResponses(?Discovery $discovery, string $serverName, string $type): array
    {
        $responses = [];
        foreach ($discovery?->resourcesFor($serverName) ?? [] as $descriptor) {
            if ($descriptor->type !== $type) {
                continue;
            }
            foreach ($descriptor->responses[OperationType::Delete->value] ?? [] as $entry) {
                $responses[] = new DeclaredOperationResponse($entry['status'], $entry['jobType']);
            }
        }

        return $responses;
    }
}
