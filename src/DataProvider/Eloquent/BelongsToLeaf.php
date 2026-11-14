<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;

/**
 * Finds the `belongsTo` a traversal path ends on: the relation the leaf names, or the one
 * whose foreign key the leaf column is, so either form compares the key of the model the
 * relation points at.
 *
 * @internal
 */
final class BelongsToLeaf
{
    /**
     * The relation method whose foreign key a model's column is, by model class and column,
     * or null when none is.
     *
     * @var array<string, ?string>
     */
    private static array $owningRelations = [];

    /**
     * @return EloquentBelongsTo<Model, Model>|null
     */
    public static function of(Model $model, string $leaf): ?EloquentBelongsTo
    {
        return self::named($model, $leaf) ?? self::owning($model, $leaf);
    }

    /**
     * The `belongsTo` relation `$leaf` names on `$model` when a traversal path ends on a
     * relation method rather than a column, such as a pivot model's `definition`, so the
     * path compares the foreign key with the id of the model it points at. Null for a
     * column.
     *
     * @return EloquentBelongsTo<Model, Model>|null
     */
    private static function named(Model $model, string $leaf): ?EloquentBelongsTo
    {
        if (!\method_exists($model, $leaf) || $model->hasAttributeMutator($leaf) || \array_key_exists($leaf, $model->getAttributes())) {
            return null;
        }

        $relation = $model->{$leaf}();

        /** @var EloquentBelongsTo<Model, Model>|null */
        return $relation instanceof EloquentBelongsTo ? $relation : null;
    }

    /**
     * The `belongsTo` whose foreign key is the column `$leaf` on `$model`, such as a pivot
     * model's `definition_id`, so a path naming the raw column compares it exactly as the
     * path naming the relation does. Found among the model's public relation methods that
     * declare a `BelongsTo` return type and take no argument; null when none holds it.
     *
     * @return EloquentBelongsTo<Model, Model>|null
     */
    private static function owning(Model $model, string $leaf): ?EloquentBelongsTo
    {
        $key = $model::class . "\0" . $leaf;
        if (!\array_key_exists($key, self::$owningRelations)) {
            self::$owningRelations[$key] = null;
            foreach ((new \ReflectionClass($model))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || !$type instanceof \ReflectionNamedType
                    || !\is_a($type->getName(), EloquentBelongsTo::class, true) || $method->getDeclaringClass()->getName() === Model::class) {
                    continue;
                }

                $relation = $method->invoke($model);
                // A morphTo's foreign key points at several models, so no one key decodes it.
                if ($relation instanceof EloquentBelongsTo && !$relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo && $relation->getForeignKeyName() === $leaf) {
                    self::$owningRelations[$key] = $method->getName();

                    break;
                }
            }
        }

        $name = self::$owningRelations[$key];
        if ($name === null) {
            return null;
        }

        $relation = $model->{$name}();

        /** @var EloquentBelongsTo<Model, Model>|null */
        return $relation instanceof EloquentBelongsTo ? $relation : null;
    }
}
