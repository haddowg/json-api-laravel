<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;

final class GadgetResource extends AbstractResource
{
    public static string $type = 'gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('colour'),
            Integer::make('weight'),
            Str::make('size'),
        ];
    }

    public function filters(): array
    {
        return [
            Contains::make('name'),
            Where::make('colour'),
            Range::make('weightRange', 'weight'),
            Where::make('size')->default('large'),
        ];
    }
}
