<?php

declare(strict_types=1);

namespace Workbench\App\RelationOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A shelf whose to-manys read in different orders: `books` newest first by its own
 * default, `favourites` by the books' title default, and `classics` by its Eloquent
 * relation method's `orderBy`, then the books' default. {@see PickingShelfResource} adds
 * `picks`, ordered by a pivot rank only a provider querying the join can read.
 */
final class ShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('books', 'books')
                ->defaultSort(new SortDirective(SortByField::make('year'), descending: true)),
            BelongsToMany::make('favourites', 'books')->withData(),
            BelongsToMany::make('classics', 'books'),
        ];
    }
}
