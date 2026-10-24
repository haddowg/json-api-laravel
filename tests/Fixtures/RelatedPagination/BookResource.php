<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedPagination;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Declares its own page size and cap, unlike the server default, so a relation that
 * declares no paginator shows which of the two its related collection is served with.
 */
#[AsJsonApiResource(readOnly: true)]
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

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return PagePaginator::make()->withDefaultPerPage(2)->withMaxPerPage(3);
    }
}
