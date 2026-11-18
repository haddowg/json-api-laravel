<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSorts;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetExpression;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentKeysetSortArmInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;

/**
 * The top score is a subquery the cursor reads back from the query, since the model can't
 * compute it. The tier is a bare `CASE` and the points a bare `COALESCE`.
 *
 * @implements EloquentKeysetSortArmInterface<Model>
 */
final class TeamEloquentArm implements EloquentKeysetSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof TeamSort;
    }

    public function apply(SortInterface $sort, Builder $query, bool $descending): void
    {
        \assert($sort instanceof TeamSort);

        $query->orderBy(new Expression('(' . $this->sql($sort) . ')'), $descending ? 'desc' : 'asc');
    }

    public function keysetExpression(KeysetSortInterface $sort, Builder $query): EloquentKeysetExpression
    {
        \assert($sort instanceof TeamSort);
        $expression = EloquentKeysetExpression::make($this->sql($sort));

        return $sort->key === 'topScore' ? $expression->valueFromQuery() : $expression;
    }

    /**
     * The provider parenthesises the expression, so the subquery needs no brackets of its own.
     *
     * @return literal-string
     */
    private function sql(TeamSort $sort): string
    {
        return match ($sort->key) {
            'topScore' => 'select max(score_members.score) from score_members where score_members.score_team_id = score_teams.id',
            'tier' => 'CASE WHEN score_teams.base >= 10 THEN 2 WHEN score_teams.base < 10 THEN 1 END',
            default => 'COALESCE(score_teams.bonus, score_teams.base)',
        };
    }
}
