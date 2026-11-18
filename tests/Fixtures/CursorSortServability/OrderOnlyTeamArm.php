<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CursorSortServability;

use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentSortArmInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\KeysetSorts\TeamSort;

/**
 * Orders a page by a team sort but supplies no keyset expression.
 *
 * @implements EloquentSortArmInterface<Model>
 */
final class OrderOnlyTeamArm implements EloquentSortArmInterface
{
    public function supports(SortInterface $sort): bool
    {
        return $sort instanceof TeamSort;
    }

    public function apply(SortInterface $sort, Builder $query, bool $descending): void
    {
        $query->orderBy('name', $descending ? 'desc' : 'asc');
    }
}
