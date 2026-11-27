<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Resource\DeclaresRelatedOnlyTypesInterface;
use haddowg\JsonApi\Resource\DeclaresSingletonTypesInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\ProvidesOutputTimezoneInterface;
use haddowg\JsonApi\Resource\ProvidesRelationAuthorizerInterface;
use haddowg\JsonApi\Resource\RejectsReadOnlyAttributesInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The resolver a document's relationships are built through so that a `belongsToMany` pivot
 * relation renders `meta.pivot` at any depth of the include tree, not only on the related and
 * relationship endpoints.
 *
 * It hands out the server's serializers, decorating each type that has a pivot relation, or
 * reaches one through its relations, with a {@see PivotLinkageParentSerializer}. That
 * decorator builds the type's relationships back through this resolver, which is how a type
 * reached through an include passes it on to the next level. A type that reaches no pivot
 * relation gets the server's serializer as it is.
 *
 * For one parent's pivot relation, {@see forRelation()} binds a {@see PivotMetaSerializer}
 * for that parent, which renders the member's computed fields and the stored values
 * `$storedPivotOf` reads off the member the include loaded, with no query of its own. A
 * polymorphic relation is built through the server, so the types below it render no pivot.
 */
final class PivotRenderingResolver implements SerializerResolverInterface, ProvidesOutputTimezoneInterface, ProvidesRelationAuthorizerInterface, RejectsReadOnlyAttributesInterface, DeclaresRelatedOnlyTypesInterface, DeclaresSingletonTypesInterface
{
    use ForwardsResolverSeams;

    /**
     * @var array<string, SerializerInterface>
     */
    private array $serializers = [];

    /**
     * @var array<string, bool>
     */
    private array $reaches = [];

    /**
     * @param SerializerResolverInterface                                              $inner         the server
     * @param \Closure(string): list<RelationInterface>                                $relationsOf   the relations a type declares
     * @param \Closure(object, BelongsToMany): ((\Closure(object): ?array<string, mixed>)|null) $storedPivotOf for a parent's pivot relation, reads a member's stored values, or `null` when the relation's provider reads none
     */
    public function __construct(
        private readonly SerializerResolverInterface $inner,
        private readonly \Closure $relationsOf,
        private readonly \Closure $storedPivotOf,
    ) {}

    public function serializerFor(string $type): SerializerInterface
    {
        return $this->serializers[$type] ??= $this->reachesPivot($type)
            ? new PivotLinkageParentSerializer($this->inner->serializerFor($type), $this)
            : $this->inner->serializerFor($type);
    }

    public function hasSerializerFor(string $type): bool
    {
        return $this->inner->hasSerializerFor($type);
    }

    /**
     * The resolver `$relation` builds its relationship through for `$parent`: one binding
     * the parent's pivot values to the related type for a pivot relation, the server for a
     * polymorphic one, and this resolver otherwise.
     */
    public function forRelation(mixed $parent, RelationInterface $relation): SerializerResolverInterface
    {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1 || $relation instanceof PolymorphicRelationInterface) {
            return $this->inner;
        }

        if (!$relation instanceof BelongsToMany || $relation->pivotFields() === [] || !\is_object($parent) || !$this->hasSerializerFor($relatedTypes[0])) {
            return $this;
        }

        return new PivotSubstitutingResolver($this, $relatedTypes[0], new PivotMetaSerializer(
            $this->serializerFor($relatedTypes[0]),
            [],
            $relation,
            $parent,
            $this->outputTimezone(),
            $relation->storedPivotFields() === [] ? null : ($this->storedPivotOf)($parent, $relation),
        ));
    }

    protected function decorated(): SerializerResolverInterface
    {
        return $this->inner;
    }

    /**
     * Whether `$type` declares a pivot relation or reaches one through its monomorphic
     * relations.
     */
    private function reachesPivot(string $type): bool
    {
        if (isset($this->reaches[$type])) {
            return $this->reaches[$type];
        }

        $seen = [$type => true];
        $queue = [$type];
        while ($queue !== []) {
            $current = \array_shift($queue);
            if (!$this->inner->hasSerializerFor($current)) {
                continue;
            }
            foreach (($this->relationsOf)($current) as $relation) {
                $relatedTypes = $relation->relatedTypes();
                if (\count($relatedTypes) !== 1 || $relation instanceof PolymorphicRelationInterface) {
                    continue;
                }
                if ($relation instanceof BelongsToMany && $relation->pivotFields() !== []) {
                    return $this->reaches[$type] = true;
                }
                if (!isset($seen[$relatedTypes[0]])) {
                    $seen[$relatedTypes[0]] = true;
                    $queue[] = $relatedTypes[0];
                }
            }
        }

        return $this->reaches[$type] = false;
    }
}
