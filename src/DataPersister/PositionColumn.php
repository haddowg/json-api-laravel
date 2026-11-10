<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataPersister;

use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\PositionedRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\Server\IdEncoderResolver;

/**
 * The column a relation positioned with `positionedBy()` stores each member's position in: its
 * pivot field's column on a link row, or the related type's field column on the related row.
 *
 * @internal
 */
final class PositionColumn
{
    public static function of(RelationInterface $relation, ?IdEncoderResolver $resources = null): ?string
    {
        $name = $relation instanceof PositionedRelationInterface ? $relation->positionField() : null;
        if ($name === null) {
            return null;
        }

        if ($relation instanceof PivotRelationInterface) {
            return $relation->pivotField($name)?->column() ?? $name;
        }

        $type = $relation->relatedTypes()[0] ?? null;
        foreach ($type !== null ? ($resources?->resourceFor($type)?->allFields() ?? []) : [] as $field) {
            if ($field->name() === $name && !$field instanceof RelationInterface) {
                return $field->column() ?? $name;
            }
        }

        return $name;
    }
}
