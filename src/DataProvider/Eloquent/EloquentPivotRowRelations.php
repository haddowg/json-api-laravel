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
 * provider serves with the model it targets.
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
        $types = \array_keys(\array_filter($this->modelByType, static fn(string $model): bool => $model === $target));
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
     * A fresh pivot row of `$relation` declared on `$parent`, or `null` when it is no
     * `belongsToMany` of that model. A `morphToMany` reads no pivot rows a path walks.
     *
     * @param class-string<Model>|null $parent
     */
    public static function pivotRow(RelationInterface $relation, ?string $parent): ?Pivot
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
        if (!$eloquent instanceof EloquentBelongsToMany || $eloquent instanceof EloquentMorphToMany) {
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
