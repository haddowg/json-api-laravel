<?php

declare(strict_types=1);

namespace Workbench\App\RelationOrder;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * The Eloquent provider's shelf: a {@see ShelfResource} whose `picks` read by their pivot
 * rank, which the in-memory provider would refuse at warm-up, and `rankedPicks`, the same
 * picks paged by cursor.
 */
final class PickingShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            ...(new ShelfResource())->fields(),
            BelongsToMany::make('picks', 'books')
                ->fields(Integer::make('rank'))
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false)),
            BelongsToMany::make('rankedPicks', 'books')
                ->storedAs('picks')
                ->fields(Integer::make('rank'))
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false))
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
