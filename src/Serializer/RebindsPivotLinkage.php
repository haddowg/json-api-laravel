<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * The shared pivot-linkage rebind the pivot parent-serializer decorator uses: given a base
 * resolver (the Server) and a {@see PivotMetaSerializer} holding the per-member pivot map
 * for one relation, it returns the relationship callable core invokes for that relation
 * name — rebuilding the relationship through the relation's public
 * {@see RelationInterface::buildRelationship()} with a {@see PivotSubstitutingResolver} that
 * binds the {@see PivotMetaSerializer} for the related type, so each linkage identifier
 * carries its `meta.pivot` (riding core's identifier-meta render path with no core change).
 *
 * {@see PivotParentSerializer} rebinds ONE relation (the relationship-linkage endpoint and the
 * relationship mutation echo). A read's document rebinds every relation through
 * {@see PivotLinkageParentSerializer} instead.
 *
 * The callables come from core's {@see RendersRelationsTrait::relationshipCallable()}, so a
 * relation declared `notSparseField()` still renders whatever `fields[type]` lists.
 */
trait RebindsPivotLinkage
{
    use RendersRelationsTrait;

    /**
     * The relationship callable for `$relation`, rebuilding its linkage through a
     * {@see PivotSubstitutingResolver} over `$pivotSerializer` so each identifier carries
     * its pivot meta. The callable matches the core relationship-builder signature core
     * invokes from a serializer's `getRelationships()` map.
     *
     * @return callable(mixed, JsonApiRequestInterface, string): AbstractRelationship
     */
    protected function pivotLinkageBuilder(
        RelationInterface $relation,
        SerializerResolverInterface $resolver,
        PivotMetaSerializer $pivotSerializer,
    ): callable {
        return self::relationshipCallable($relation, new PivotSubstitutingResolver(
            $resolver,
            $relation->relatedTypes()[0] ?? '',
            $pivotSerializer,
        ));
    }
}
