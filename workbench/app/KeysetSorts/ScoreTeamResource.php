<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSorts;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `scoreTeams` resource served by both cursor-sort conformance concretes: cursor
 * pages ordered by the {@see TeamSort} keys.
 */
#[AsJsonApiResource(readOnly: true)]
final class ScoreTeamResource extends AbstractResource
{
    public static string $type = 'scoreTeams';

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            Str::make('name')->sortable(),
        ];
    }

    public function sorts(): array
    {
        return [new TeamSort('topScore'), new TeamSort('tier'), new TeamSort('points')];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return CursorPaginator::make()->withDefaultSize(2);
    }
}
