<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Field\StoredIdForms;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Eloquent's eager load of one relation for a page of parents: one query, then its match onto
 * each parent. Eloquent matches a row to its parent by the key exactly, while a column that
 * compares without case may return a join row or child row holding a parent's uuid-like key in
 * another case. Such a row is read as holding the parent's own spelling first, so it lands on
 * that parent.
 *
 * @internal
 */
final class EagerMatch
{
    /**
     * Loads `$eager` for `$models` and sets each parent's `$method` relation.
     *
     * @param Relation<Model, Model, *> $eager
     * @param list<Model>                   $models
     *
     * @return list<Model>
     */
    public static function run(Relation $eager, array $models, string $method): array
    {
        $eager->addEagerConstraints($models);
        $models = \array_values($eager->initRelation($models, $method));
        $results = $eager->getEager();
        self::align($eager, $models, $results);
        $eager->match($models, $results, $method);

        return $models;
    }

    /**
     * Rewrites the parent key a join row of a `belongsToMany`, or a child row of a `hasOne` or
     * `hasMany`, holds in another case than its parent to the parent's spelling, as if it had
     * been read so. Any other relation, and any key that isn't uuid-like, is left alone.
     *
     * @param Relation<Model, Model, *> $eager
     * @param list<Model>                   $models
     * @param EloquentCollection<int, Model> $results
     */
    private static function align(Relation $eager, array $models, EloquentCollection $results): void
    {
        if ($eager instanceof BelongsToMany) {
            $parentKey = $eager->getParentKeyName();
            $held = $eager->getForeignPivotKeyName();
            $accessor = $eager->getPivotAccessor();
            $rowOf = static fn(Model $result): ?Model => $result->relationLoaded($accessor) && $result->getRelation($accessor) instanceof Model ? $result->getRelation($accessor) : null;
        } elseif ($eager instanceof HasOneOrMany) {
            $parentKey = $eager->getLocalKeyName();
            $held = $eager->getForeignKeyName();
            $rowOf = static fn(Model $result): Model => $result;
        } else {
            return;
        }

        $spelling = [];
        foreach ($models as $model) {
            $key = StoredKey::on($model, $parentKey);
            if (\is_string($key) && StoredIdForms::isUuidLike($key)) {
                $spelling[\strtolower($key)] = $key;
            }
        }
        if ($spelling === []) {
            return;
        }

        foreach ($results as $result) {
            $row = $rowOf($result);
            $key = $row !== null ? StoredKey::on($row, $held) : null;
            if (!\is_string($key) || $row === null) {
                continue;
            }
            $parent = $spelling[\strtolower($key)] ?? null;
            if ($parent !== null && $parent !== $key) {
                $row->setRawAttributes([$held => $parent] + $row->getAttributes(), true);
            }
        }
    }
}
