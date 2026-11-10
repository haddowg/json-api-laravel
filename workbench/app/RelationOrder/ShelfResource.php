<?php

declare(strict_types=1);

namespace Workbench\App\RelationOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A shelf whose to-manys read in different orders: `books` newest first by its own
 * default, `favourites` by the books' title default, `picks` by a pivot rank, and
 * `classics` by its Eloquent relation method's `orderBy`, then the books' default.
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
            BelongsToMany::make('picks', 'books')
                ->fields(Integer::make('rank'))
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false)),
            BelongsToMany::make('classics', 'books'),
        ];
    }
}
