<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\DataProvider\ScopesFilterHopsInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;

/**
 * Executes a requested sort order against an Eloquent query {@see Builder}: the
 * directives arrive most significant first (one composite call) and
 * append as sequential `orderBy` terms, so the request's first `sort` field is the
 * primary key, as the spec requires. Any non-{@see SortByField} directive (a
 * computed/multi-column sort) has no generic SQL translation and raises
 * {@see UnsupportedSort} unless a registered {@see EloquentSortArmInterface} handles
 * it — the Eloquent half of the framework's extensible-handler seam.
 *
 * A {@see SortByField} declared with `->through()` orders by a correlated subselect per to-one
 * relation of its path, each built from the relation's own existence query over the related
 * model, so its global scopes apply, and the innermost selecting the leaf column. A relation
 * that holds nothing, a row a scope hides, and a relation a default order may not read through
 * all leave the subselect empty, so the row sorts as a null column does. On a cursor page the
 * level selects the subselect and the cursor reads its boundary value from the query.
 *
 * @implements SortHandlerInterface<Builder<Model>>
 */
final class EloquentSortHandler implements SortHandlerInterface, ScopesFilterHopsInterface
{
    /**
     * Data-layer-specific remediation appended to the core {@see UnsupportedSort}
     * message when a custom sort reaches this handler with no arm to run it.
     */
    private const string ARM_HINT = 'To run a custom sort on the Eloquent provider, register an EloquentSortArmInterface on the EloquentDataProvider (constructor $sortArms).';

    /**
     * The remediation when a cursor page is ordered by a custom sort with no keyset arm.
     */
    private const string KEYSET_ARM_HINT = 'To order a cursor page by a custom sort on the Eloquent provider, its arm must implement EloquentKeysetSortArmInterface.';

    /**
     * @var list<EloquentSortArmInterface<Model>>
     */
    private readonly array $arms;

    /**
     * @var EloquentBelongsToMany<Model, Model, *, *>|null
     */
    private ?EloquentBelongsToMany $pivot = null;

    /**
     * Whether a `pivot.`-prefixed column names a column of the query's own model, the link row
     * an identified `MorphToMany` queries.
     */
    private bool $rows = false;

    /**
     * The paths the sorts being applied run with, which name the relations a default order
     * reads `null` through.
     */
    private ?FilterPaths $paths = null;

    /**
     * @param iterable<EloquentSortArmInterface<Model>> $arms author arms for custom sort types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
    }

    /**
     * A copy of this handler that resolves a `pivot.`-prefixed column to `$relation`'s join
     * table, for a query built off that `belongsToMany`.
     *
     * @param EloquentBelongsToMany<Model, Model, *, *> $relation
     */
    public function forPivot(EloquentBelongsToMany $relation): self
    {
        $handler = clone $this;
        $handler->pivot = $relation;

        return $handler;
    }

    /**
     * A copy of this handler that reads a `pivot.`-prefixed column off the query's own model,
     * for a query over an identified `MorphToMany`'s link rows.
     */
    public function forRows(): self
    {
        $handler = clone $this;
        $handler->rows = true;

        return $handler;
    }

    public function forPaths(FilterPaths $paths): static
    {
        $handler = clone $this;
        $handler->paths = $paths;

        return $handler;
    }

    public function apply(array $sorts, mixed $query): mixed
    {
        if (!$query instanceof Builder) {
            throw new \LogicException(\sprintf(
                'The %s expects a %s query; got %s.',
                self::class,
                Builder::class,
                \get_debug_type($query),
            ));
        }

        foreach ($sorts as $directive) {
            $sort = $directive->sort;
            if ($sort instanceof SortByField && $sort->through !== null) {
                $query->orderBy($this->throughValue($sort, $query), $directive->descending ? 'desc' : 'asc');

                continue;
            }

            if ($sort instanceof SortByField) {
                $query->orderBy($this->qualify($query, $sort->column), $directive->descending ? 'desc' : 'asc');

                continue;
            }

            $this->applyArm($sort, $query, $directive->descending);
        }

        return $query;
    }

    /**
     * Delegates a custom {@see SortInterface} to the first registered
     * {@see EloquentSortArmInterface} that supports it; {@see UnsupportedSort} when
     * none does.
     *
     * @param Builder<Model> $query
     */
    private function applyArm(SortInterface $sort, Builder $query, bool $descending): void
    {
        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                $arm->apply($sort, $query, $descending);

                return;
            }
        }

        throw new UnsupportedSort($sort, self::ARM_HINT);
    }

    /**
     * Whether a cursor page can order by `$sort`: a field sort always, a custom sort when
     * the first arm supporting it implements {@see EloquentKeysetSortArmInterface}.
     */
    public function keysCursorSort(SortInterface $sort): bool
    {
        if ($sort instanceof SortByField) {
            return true;
        }

        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                return $arm instanceof EloquentKeysetSortArmInterface;
            }
        }

        return false;
    }

    /**
     * The expression a custom sort orders a cursor page of `$query` by, from the first
     * registered arm that supports it; {@see UnsupportedSort} when that arm is not an
     * {@see EloquentKeysetSortArmInterface}, or no arm supports the sort.
     *
     * @param Builder<Model> $query
     */
    public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression
    {
        if ($sort instanceof SortByField && $sort->through !== null) {
            $value = $this->throughValue($sort, $query)->toBase();

            // The boundary row's value is the one the scoped subselect reached, so the cursor
            // reads it from the query rather than walking the model's relations.
            return EloquentKeysetExpression::make($value->toSql(), $value->getBindings())->valueFromQuery();
        }

        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                if (!$arm instanceof EloquentKeysetSortArmInterface) {
                    break;
                }

                return $arm->keysetExpression($sort, $query);
            }
        }

        throw new UnsupportedSort($sort, self::KEYSET_ARM_HINT);
    }

    /**
     * The correlated subselect reading `$sort`'s leaf through each to-one relation of its
     * resolved path off `$query`'s model: one existence query per relation, from the related
     * model's own query so its global scopes apply, the innermost selecting the leaf column
     * and each outer one selecting the next, one row each.
     *
     * @param Builder<Model> $query
     *
     * @return Builder<Model>
     */
    private function throughValue(SortByField $sort, Builder $query): Builder
    {
        $leaf = $sort->atLeaf();
        $path = $sort->resolvedThrough();
        \assert($path !== null);

        $parent = $query;
        $model = $query->getModel();
        $subqueries = [];
        foreach ($path->hops as $hop) {
            $method = $hop->storage();
            $relation = \method_exists($model, $method) ? $model->{$method}() : null;
            if (!$relation instanceof EloquentRelation) {
                throw new \LogicException(\sprintf('Sort "%s" reaches through "%s", but "%s" is not a relation of %s.', $sort->key(), $path->path, $method, $model::class));
            }

            /** @var Builder<Model> $related */
            $related = $relation->getRelated()->newQuery();
            /** @var Builder<Model> $subquery */
            $subquery = $relation->getRelationExistenceQuery($related, $parent);
            if ($this->paths?->refuses($hop->relation) === true) {
                $subquery->whereRaw('1 = 0');
            }

            $subqueries[] = $subquery;
            $parent = $subquery;
            $model = $subquery->getModel();
        }

        $value = null;
        foreach (\array_reverse($subqueries) as $subquery) {
            $subquery->getQuery()->select([]);
            $value === null
                ? $subquery->select($subquery->getModel()->qualifyColumn($leaf->column))
                : $subquery->selectSub($value, 'jsonapi_through');
            $value = $subquery->limit(1);
        }
        \assert($value !== null);

        return $value;
    }

    /**
     * The table-qualified sort column, validated as an identifier path and qualified
     * against the query's model table.
     *
     * @param Builder<Model> $query
     */
    private function qualify(Builder $query, string $column): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a valid column path.', $column));
        }

        $column = $this->rowColumn($column);
        if ($this->pivot !== null && \str_starts_with($column, RelationCriteriaFactory::PIVOT_PREFIX)) {
            return $this->pivot->getTable() . '.' . \substr($column, \strlen(RelationCriteriaFactory::PIVOT_PREFIX));
        }

        return $query->getModel()->qualifyColumn($column);
    }

    /**
     * `$column` with its `pivot.` prefix stripped when it names a link row's own column.
     */
    private function rowColumn(string $column): string
    {
        return $this->rows && \str_starts_with($column, RelationCriteriaFactory::PIVOT_PREFIX)
            ? \substr($column, \strlen(RelationCriteriaFactory::PIVOT_PREFIX))
            : $column;
    }
}
