<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
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
 * the inner serializer built it. Each callable comes from core's
 * {@see \haddowg\JsonApi\Resource\RendersRelationsTrait::relationshipCallable()}, so a relation declared
 * `notSparseField()` still renders whatever `fields[type]` lists.
 */
final class PivotLinkageParentSerializer extends AbstractPivotParentSerializer
{
    /**
     * @param DeclaresRelationsInterface|null $declaring the serializer declaring the relations, when the inner one doesn't
     * @param string|null                     $type      the one type whose resources this decorator rebinds, when the inner serializer renders several
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly PivotRenderingResolver $resolver,
        private readonly ?DeclaresRelationsInterface $declaring = null,
        private readonly ?string $type = null,
    ) {}

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);
        $declaring = $this->declaring ?? ($this->inner instanceof DeclaresRelationsInterface ? $this->inner : null);
        if ($declaring === null) {
            return $relationships;
        }

        $type = $this->inner->getType($object);
        if ($this->type !== null && $type !== $this->type) {
            return $relationships;
        }

        foreach ($relationships as $name => $callable) {
            $relation = $declaring->relationNamedIncludingHidden((string) $name);
            if ($relation !== null) {
                $relationships[$name] = self::relationshipCallable($relation, $this->resolver->forRelation($object, $relation, $type));
            }
        }

        return $relationships;
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
