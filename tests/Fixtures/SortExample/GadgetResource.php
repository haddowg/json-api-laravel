<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\SortExample;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A type with a default order and no `sort` example of its own.
 */
final class GadgetResource extends AbstractResource
{
    public static string $type = 'gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->sortable(),
            Integer::make('price')->sortable(),
        ];
    }

    public function defaultSort(): array
    {
        return [new SortDirective(SortByField::make('name'), descending: false)];
    }

}
