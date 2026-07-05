<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Event;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Response\IdentifierResponse;

/**
 * Dispatched after a relationship-endpoint mutation commits. A listener may
 * **replace** the linkage response via {@see setResponse()}; the handler reads
 * the (possibly replaced) {@see response()} back. {@see $relation}, {@see $linkage}
 * and {@see $mode} describe the applied change. Deferred to post-commit under an
 * active Atomic Operations batch (replacement inert). Routed to
 * {@see \haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface::afterRelationshipMutate()}.
 */
final class AfterRelationshipMutateEvent
{
    private ?IdentifierResponse $response = null;

    /**
     * @param ToOneRelationship|ToManyRelationship $linkage the parsed linkage
     */
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly ToOneRelationship|ToManyRelationship $linkage,
        public readonly Mode $mode,
        public readonly string $serverName,
    ) {}

    public function setResponse(?IdentifierResponse $response): void
    {
        $this->response = $response;
    }

    public function response(): ?IdentifierResponse
    {
        return $this->response;
    }
}
