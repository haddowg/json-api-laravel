<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * `fk-teams`, whose model declares no relation to its members, read through their `team`.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class FkTeamResource extends AbstractResource
{
    public static string $type = 'fk-teams';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('members', 'fk-members', 'team')->paginate(PagePaginator::make()->withDefaultPerPage(2))->countable(),
        ];
    }
}
