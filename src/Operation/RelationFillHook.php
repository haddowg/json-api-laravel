<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Operation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Runs a relation's `fillUsing()` hook for a relationship write. A relation that declares one
 * owns how its linkage lands on the entity, so the write goes through core's hydrate path
 * instead of the persister's relationship seam.
 *
 * @internal
 */
final class RelationFillHook
{
    /**
     * @var \WeakMap<AbstractFieldValue, bool>|null
     */
    private static ?\WeakMap $declared = null;

    /**
     * Whether `$relation` declares a `fillUsing()` hook.
     */
    public static function declaredBy(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue) {
            return false;
        }

        self::$declared ??= new \WeakMap();
        if (!isset(self::$declared[$relation])) {
            // Core exposes no accessor for the hook, so it is read off the field's state.
            $state = (new \ReflectionProperty(AbstractFieldValue::class, 'state'))->getValue($relation);
            self::$declared[$relation] = $state instanceof FieldState && $state->fillUsing !== null;
        }

        return self::$declared[$relation];
    }

    /**
     * Applies `$linkage` to `$entity` through the hook and returns the entity it yields.
     */
    public static function apply(RelationInterface $relation, object $entity, ToOneRelationship|ToManyRelationship $linkage, Mode $mode): object
    {
        $result = $linkage instanceof ToManyRelationship
            ? $relation->applyToMany($entity, $linkage, $mode)
            : $relation->hydrateRelationship($entity, $linkage);

        return \is_object($result) ? $result : $entity;
    }
}
