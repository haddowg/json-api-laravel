<?php

declare(strict_types=1);

namespace Workbench\App\SortFamily;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetExpression;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetSortArmInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Orders by a {@see PlaceholderValueSort} by joining the placeholder's row from
 * `sort_campaign_values` and casting its text value to a number, on page and cursor
 * pages alike.
 *
 * @implements EloquentKeysetSortArmInterface<Model>
 */
final class PlaceholderValueEloquentArm implements EloquentKeysetSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof PlaceholderValueSort;
    }

    public function apply(SortInterface $sort, Builder $query, bool $descending): void
    {
        \assert($sort instanceof PlaceholderValueSort);

        $query->orderBy(new Expression($this->join($sort, $query)), $descending ? 'desc' : 'asc');
    }

    public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression
    {
        \assert($sort instanceof PlaceholderValueSort);

        return EloquentKeysetExpression::make($this->join($sort, $query));
    }

    /**
     * Left-joins the placeholder's value row, so a campaign without one sorts as null, and
     * returns the expression for its value.
     *
     * @param Builder<Model> $query
     */
    private function join(PlaceholderValueSort $sort, Builder $query): string
    {
        // The placeholder names an alias, so only plain identifiers reach the SQL.
        if (\preg_match('/^[a-z_]+$/', $sort->placeholder) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a placeholder name.', $sort->placeholder));
        }

        $alias = 'sort_value_' . $sort->placeholder;
        $model = $query->getModel();

        if ($query->getQuery()->columns === null) {
            $query->select($model->qualifyColumn('*'));
        }

        $query->leftJoin('sort_campaign_values as ' . $alias, function (JoinClause $join) use ($alias, $model, $sort): void {
            $join->on($alias . '.sort_campaign_id', '=', $model->getQualifiedKeyName())
                ->where($alias . '.name', '=', $sort->placeholder);
        });

        return 'CAST(' . $alias . '.value AS REAL)';
    }
}
