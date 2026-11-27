<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Internal\NonSparseMember;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A parent-serializer decorator that builds every relationship its inner serializer renders
 * through a {@see PivotRenderingResolver}, so a `belongsToMany` pivot relation's linkage and
 * the resources it includes carry `meta.pivot` on a primary document, and so does every
 * resource the include tree reaches below it.
 *
 * It keeps the inner serializer's relationship set, so a relation the inner serializer hides
 * for this request stays hidden, and replaces only the callable of a relation the inner
 * serializer declares. A custom relationship callable with no declared relation renders as
 * the inner serializer built it.
 */
final class PivotLinkageParentSerializer extends AbstractPivotParentSerializer
{
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly PivotRenderingResolver $resolver,
    ) {}

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);
        if (!$this->inner instanceof DeclaresRelationsInterface) {
            return $relationships;
        }

        $resolver = $this->resolver;
        $type = $this->inner->getType($object);
        foreach ($relationships as $name => $callable) {
            $relation = $this->inner->relationNamedIncludingHidden((string) $name);
            if ($relation === null) {
                continue;
            }

            $build = static fn(mixed $model, JsonApiRequestInterface $req, string $relationshipName): AbstractRelationship
                => $relation->buildRelationship($model, $req, $resolver->forRelation($model, $relation, $type));

            // A not-sparse relation renders whatever `fields[type]` lists, which core reads off the callable's class.
            $relationships[$name] = $callable instanceof NonSparseMember ? new NonSparseMember($build) : $build;
        }

        return $relationships;
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
