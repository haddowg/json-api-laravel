<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSorts;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A division every team belongs to, whose teams render their first page with it best top
 * score first and lowest tier first by cursor, and most points first by page number,
 * through the teams' foreign key.
 */
#[AsJsonApiResource(readOnly: true)]
final class ScoreDivisionResource extends AbstractResource
{
    public static string $type = 'scoreDivisions';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('rankedTeams', 'scoreTeams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('topScore'), true))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
            HasMany::make('tieredTeams', 'scoreTeams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('tier'), false))
                ->paginate(CursorPaginator::make()->withDefaultSize(2))
                ->withData(),
            HasMany::make('pointedTeams', 'scoreTeams')
                ->storedAs('teams')
                ->defaultSort(new SortDirective(new TeamSort('points'), true))
                ->paginate(PagePaginator::make()->withDefaultPerPage(2))
                ->withData(),
        ];
    }
}
