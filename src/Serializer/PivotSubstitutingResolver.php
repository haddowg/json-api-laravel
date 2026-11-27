<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Resource\DeclaresRelatedOnlyTypesInterface;
use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApi\Resource\ProvidesResourcesInterface;
use haddowg\JsonApi\Resource\RejectsReadOnlyAttributesInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see SerializerResolverInterface} decorator that substitutes one type's serializer
 * for a {@see PivotMetaSerializer}, delegating every other resolution to the inner resolver
 * (the {@see \haddowg\JsonApi\Server\Server}).
 *
 * Used to render pivot meta on the **relationship-linkage** endpoint
 * (`GET /{type}/{id}/relationships/{rel}`): that endpoint renders the parent's relationship
 * through the parent serializer, which resolves the related type's serializer through a
 * resolver to build the linkage. Passing this resolver to the relation's
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::buildRelationship()} binds the
 * {@see PivotMetaSerializer} to the linkage, so each identifier carries its `meta.pivot` —
 * riding core's existing identifier-meta render path with no core change.
 *
 * It passes the inner resolver's output timezone, relation authorizer, related-only types
 * and singleton types through, so the linkage it builds renders in the server's zone, asks
 * the server's authorizer, links to no endpoint a related-only type lacks and links a
 * singleton's endpoints at its type's path.
 */
final class PivotSubstitutingResolver implements SerializerResolverInterface, ProvidesOutputTimezoneInterface, ProvidesRelationAuthorizerInterface, RejectsReadOnlyAttributesInterface, DeclaresRelatedOnlyTypesInterface, DeclaresSingletonTypesInterface, ProvidesResourcesInterface
{
    use ForwardsResolverSeams;

    public function __construct(
        private readonly SerializerResolverInterface $inner,
        private readonly string $type,
        private readonly PivotMetaSerializer $pivotSerializer,
    ) {}

    public function serializerFor(string $type): SerializerInterface
    {
        return $type === $this->type ? $this->pivotSerializer : $this->inner->serializerFor($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $type === $this->type ? true : $this->inner->hasSerializerFor($type);
    }

    protected function decorated(): SerializerResolverInterface
    {
        return $this->inner;
    }
}
