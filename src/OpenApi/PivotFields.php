<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * Reads a {@see BelongsToMany} relation's declared pivot fields
 * ({@see BelongsToMany::pivotFields()} — real {@see FieldInterface} definitions) for
 * OpenAPI projection: the per-edge `meta.pivot` field inventory and the auto-derived
 * `?sort=<field>` vocabulary each pivot field contributes on the relation's
 * related/relationship endpoints.
 *
 * This is the OpenAPI-projection twin of the Symfony bundle's `DataProvider\PivotFields`
 * — the two methods the {@see \haddowg\JsonApiLaravel\OpenApi\Metadata\RelationMetadata}
 * reads so the projected document advertises exactly the pivot meta shape and pivot
 * sort keys the bundle projects for an identical relation (the Phase-5 byte-compat
 * mandate). It is pure and self-contained (it only reads the relation's declared
 * fields), so it stays correct regardless of the reference layer's pivot query
 * push-down state.
 */
final class PivotFields
{
    /**
     * The declared pivot fields for `$relation`, as a list of {@see FieldInterface}
     * definitions, or an empty list when it is not a pivot-backed relation.
     *
     * @return list<FieldInterface>
     */
    public static function declaredFor(RelationInterface $relation): array
    {
        return $relation instanceof BelongsToMany ? $relation->pivotFields() : [];
    }

    /**
     * The sort vocabulary derived from `$relation`'s pivot fields: one
     * {@see SortByField} per field, keyed by the field name and columned by its
     * declared column (defaulting to the name), so `?sort=position` orders by the pivot
     * entity's backing column. Empty for a non-pivot relation.
     *
     * @return list<SortByField>
     */
    public static function sortsFor(RelationInterface $relation): array
    {
        $sorts = [];
        foreach (self::declaredFor($relation) as $field) {
            $sorts[] = SortByField::make($field->name(), $field->column() ?? $field->name());
        }

        return $sorts;
    }
}
