<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Resource\DeclaresRelatedOnlyTypesInterface;
use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApi\Resource\RejectsReadOnlyAttributesInterface;
use haddowg\JsonApi\Resource\RelationAuthorizerInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApi\Serializer\ResourceLinkContributorInterface;

/**
 * Passes every seam of a {@see SerializerResolverInterface} other than serializer resolution
 * through to the resolver it decorates: the request-scoped relationship seams, the output
 * timezone, the relation authorizer and the related-only and singleton types, so a
 * relationship built through the decorator renders exactly as one built through the server.
 *
 * @phpstan-require-implements SerializerResolverInterface
 */
trait ForwardsResolverSeams
{
    abstract protected function decorated(): SerializerResolverInterface;

    public function relationshipLoadState(): ?RelationshipLoadStateInterface
    {
        return $this->decorated()->relationshipLoadState();
    }

    public function relationshipCount(): ?RelationshipCountInterface
    {
        return $this->decorated()->relationshipCount();
    }

    public function relationshipPagination(): ?RelationshipPaginationInterface
    {
        return $this->decorated()->relationshipPagination();
    }

    public function relationshipLinkage(): ?RelationshipLinkageInterface
    {
        return $this->decorated()->relationshipLinkage();
    }

    public function resourceLinkContributor(): ?ResourceLinkContributorInterface
    {
        return $this->decorated()->resourceLinkContributor();
    }

    public function outputTimezone(): ?string
    {
        $inner = $this->decorated();

        return $inner instanceof ProvidesOutputTimezoneInterface ? $inner->outputTimezone() : null;
    }

    public function relationAuthorizer(): ?RelationAuthorizerInterface
    {
        $inner = $this->decorated();

        return $inner instanceof ProvidesRelationAuthorizerInterface ? $inner->relationAuthorizer() : null;
    }

    public function rejectsReadOnlyAttributes(): bool
    {
        $inner = $this->decorated();

        return $inner instanceof RejectsReadOnlyAttributesInterface && $inner->rejectsReadOnlyAttributes();
    }

    public function isRelatedOnly(string $type): bool
    {
        $inner = $this->decorated();

        return $inner instanceof DeclaresRelatedOnlyTypesInterface && $inner->isRelatedOnly($type);
    }

    public function isSingleton(string $type): bool
    {
        $inner = $this->decorated();

        return $inner instanceof DeclaresSingletonTypesInterface && $inner->isSingleton($type);
    }

    public function resolveSingletonId(string $type, SingletonContext $context): ?string
    {
        $inner = $this->decorated();

        return $inner instanceof DeclaresSingletonTypesInterface ? $inner->resolveSingletonId($type, $context) : null;
    }
}
