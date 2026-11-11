<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A book is hidden from every read by default when it is archived or a draft.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('archived')->asBoolean()->default(false),
            Where::make('draft')->asBoolean()->default(false),
            Where::make('title'),
        ];
    }

    public function sorts(): array
    {
        return [SortByField::make('id')];
    }
}
