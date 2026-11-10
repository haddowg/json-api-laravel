<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * A ledger whose `entries` default to a sort neither the entries nor the relation declare.
 */
final class LedgerResource extends AbstractResource
{
    public static string $type = 'ledgers';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('entries', 'entries')
                ->defaultSort(new SortDirective(SortByField::make('postedAt'), descending: true)),
        ];
    }
}
