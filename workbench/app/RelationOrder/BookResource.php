<?php

declare(strict_types=1);

namespace Workbench\App\RelationOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A book, listed by title unless a request or a relation says otherwise.
 */
final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Integer::make('year')->sortable(),
            Str::make('genre'),
        ];
    }

    public function filters(): array
    {
        return [Where::make('genre')];
    }

    public function defaultSort(): array
    {
        return [new SortDirective(SortByField::make('title'), descending: false)];
    }
}
