<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSortLeagues;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use Workbench\App\KeysetSorts\TeamSort;

/**
 * A league whose teams page by cursor through a pivot table, so a team sort runs on the
 * related query. `rankedTeams` reads the same teams best top score first, so an included
 * page is cut by the team sort too.
 */
#[AsJsonApiResource(readOnly: true)]
final class ScoreLeagueResource extends AbstractResource
{
    public static string $type = 'scoreLeagues';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('teams', 'scoreTeams')->paginate(CursorPaginator::make()->withDefaultSize(2)),
            BelongsToMany::make('rankedTeams', 'scoreTeams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('topScore'), true))
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
