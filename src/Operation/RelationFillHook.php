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
 * instead of the persister's relationship seam (ADR 0055).
 *
 * @internal
 */
final class RelationFillHook
{
    /**
     * @var array<int, bool>
     */
    private static array $declared = [];

    /**
     * Whether `$relation` declares a `fillUsing()` hook.
     */
    public static function declaredBy(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue) {
            return false;
        }

        // Core exposes no accessor for the hook, so it is read off the field's state.
        return self::$declared[\spl_object_id($relation)] ??= (static function (AbstractFieldValue $field): bool {
            $state = (new \ReflectionProperty(AbstractFieldValue::class, 'state'))->getValue($field);

            return $state instanceof FieldState && $state->fillUsing !== null;
        })($relation);
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
