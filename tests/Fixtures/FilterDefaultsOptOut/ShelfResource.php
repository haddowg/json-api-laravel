<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Four views of one shelf's books: `books` keeps both of the books type's defaults,
 * `allBooks` and the always-emitted `listedBooks` drop both, and `withArchived` drops
 * only `archived`.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class ShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('books', 'books')->readOnly()->countable(),
            HasMany::make('allBooks', 'books')->storedAs('books')->readOnly()->countable()->withoutFilterDefaults(),
            HasMany::make('withArchived', 'books')->storedAs('books')->readOnly()->countable()->withoutFilterDefaults('archived'),
            HasMany::make('listedBooks', 'books')->storedAs('books')->readOnly()->withData()->withoutFilterDefaults(),
        ];
    }
}
