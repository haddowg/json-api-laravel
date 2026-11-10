<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A rack whose `entries` default to their pivot rank.
 */
final class RackResource extends AbstractResource
{
    public static string $type = 'racks';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('entries', 'entries')
                ->fields(Integer::make('rank'))
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false)),
        ];
    }
}
