<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Names the relations a pivot row holds from the Eloquent pivot model a `belongsToMany` joins
 * through, its `->using()` class: a relation method of that model is the relation under the
 * method's name, a to-one or a to-many as Eloquent defines it, reaching the one type this
 * provider serves with the model it targets. The row's own ends reach the parent type and the
 * relation's related type, even when another type serves the same model.
 *
 * @internal
 */
final class EloquentPivotRowRelations implements PivotRowRelations
{
    /**
     * @var array<string, RelationInterface>
     */
    private array $relations = [];

    /**
     * @param array<string, class-string<Model>> $modelByType
     */
    public function __construct(private readonly array $modelByType) {}

    /**
     * @throws \LogicException when the pivot model's relation is polymorphic, or no type this provider serves, or more than one, serves the model it targets
     */
    public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
    {
        $key = \spl_object_id($relation) . '|' . $parentType . '|' . $member;
        if (isset($this->relations[$key])) {
            return $this->relations[$key];
        }

        $row = self::pivotRow($relation, $this->modelByType[$parentType] ?? null);
        if ($row === null || !self::declaresRelation($row, $member)) {
            return null;
        }

        $held = $row->{$member}();
        if (!$held instanceof Relation) {
            return null;
        }
        if ($held instanceof MorphTo) {
            throw new \LogicException(\sprintf('The pivot rows of relation "%s" hold "%s", a morphTo, which reaches no single type, so a path through it can\'t tell whose rules apply.', $relation->name(), $member));
        }

        $target = $held->getRelated()::class;
        // The row's own ends are the parent and the member, whose types the relation names.
        $end = $held instanceof EloquentBelongsTo ? self::endType($relation, $parentType, $this->modelByType[$parentType] ?? null, $held->getForeignKeyName()) : null;
        $types = $end !== null ? [$end] : \array_keys(\array_filter($this->modelByType, static fn(string $model): bool => $model === $target));
        if (\count($types) !== 1) {
            throw new \LogicException(\sprintf(
                $types === []
                    ? 'The pivot rows of relation "%s" hold "%s", a relation to %s, which no registered type serves, so a path through it reaches no type whose rules apply.'
                    : 'The pivot rows of relation "%s" hold "%s", a relation to %s, which several types serve (%s), so a path through it can\'t tell whose rules apply.',
                $relation->name(),
                $member,
                $target,
                \implode(', ', $types),
            ));
        }

        $toOne = $held instanceof EloquentBelongsTo || $held instanceof HasOne || $held instanceof MorphOne || $held instanceof HasOneThrough;

        return $this->relations[$key] = $toOne
            ? BelongsTo::make($member, $types[0])->build()
            : HasMany::make($member, $types[0])->build();
    }

    /**
     * The type a pivot row's `belongsTo` on `$foreignKey` reaches when it is one of the row's
     * own ends: `$parentType` for the key pointing back at the parent, the relation's related
     * type for the one pointing at the member, and `null` for any other key.
     *
     * @param class-string<Model>|null $parent
     */
    private static function endType(RelationInterface $relation, string $parentType, ?string $parent, string $foreignKey): ?string
    {
        $eloquent = self::eloquentRelation($relation, $parent);

        return match (true) {
            $eloquent === null => null,
            $foreignKey === $eloquent->getForeignPivotKeyName() => $parentType,
            $foreignKey === $eloquent->getRelatedPivotKeyName() => $relation->relatedTypes()[0] ?? null,
            default => null,
        };
    }

    /**
     * The Eloquent `belongsToMany` behind `$relation` on a fresh `$parent`, or `null` when it is
     * none, or a `morphToMany`.
     *
     * @param class-string<Model>|null $parent
     *
     * @return EloquentBelongsToMany<Model, Model>|null
     */
    private static function eloquentRelation(RelationInterface $relation, ?string $parent): ?EloquentBelongsToMany
    {
        if (!$relation instanceof BelongsToMany || $parent === null) {
            return null;
        }

        $model = new $parent();
        $method = $relation->column() ?? $relation->name();
        if (!\method_exists($model, $method)) {
            return null;
        }

        $eloquent = $model->{$method}();

        return $eloquent instanceof EloquentBelongsToMany && !$eloquent instanceof EloquentMorphToMany ? $eloquent : null;
    }

    /**
     * A fresh pivot row of `$relation` declared on `$parent`, or `null` when it is no
     * `belongsToMany` of that model. A `morphToMany` reads no pivot rows a path walks.
     *
     * @param class-string<Model>|null $parent
     */
    public static function pivotRow(RelationInterface $relation, ?string $parent): ?Pivot
    {
        $eloquent = self::eloquentRelation($relation, $parent);
        if ($eloquent === null) {
            return null;
        }

        $row = $eloquent->newPivot();

        return $row instanceof Pivot ? $row : null;
    }

    /**
     * Whether `$member` is a method the pivot model's own class declares, so no method
     * Eloquent's base classes define, such as `delete`, is ever called to find out.
     */
    private static function declaresRelation(Model $row, string $member): bool
    {
        if (!\method_exists($row, $member)) {
            return false;
        }

        $method = new \ReflectionMethod($row, $member);

        return $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 0
            && !\str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\');
    }
}
