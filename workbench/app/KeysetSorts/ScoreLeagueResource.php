<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSorts;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A league holding every team. `teams` pages by cursor through the pivot table.
 * `rankedTeams` and `rankedSlots` read the same teams best top score first and render
 * their first page with the league, through the plain join and through a join whose
 * pivot carries each team's slot.
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
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
            BelongsToMany::make('rankedSlots', 'scoreTeams')
                ->storedAs('slottedTeams')
                ->fields(Integer::make('slot')->build())
                ->defaultSort(new SortDirective(new TeamSort('topScore'), true))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
        ];
    }
}
