<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;

/**
 * Three list filters and two filter families. `genre` takes a delimited list, `titles` an
 * array-only one so a comma stays inside a title, and `pageCounts` validates each member as
 * an integer. `attr.<column>` resolves to an equality filter on one column, except
 * `attr.title`, which the exact `Contains` filter answers, and `in.<column>` to a list
 * filter on one column. Pagination offers page and cursor strategies, defaulting to page.
 */
final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('genre'),
            Integer::make('pages'),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIn::make('genre')->acceptsList(),
            WhereIn::make('titles', 'title')->acceptsList(delimited: false),
            WhereIn::make('pageCounts', 'pages')->integer()->acceptsList(),
            Contains::make('attr.title', 'title'),
            FilterFamily::make('attr', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => match ($suffix) {
                'title', 'genre' => Where::make('attr.' . $suffix, $suffix)->build(),
                'pages' => Where::make('attr.pages', 'pages')->integer()->build(),
                default => null,
            })->withSuffixPattern('[a-z]+'),
            FilterFamily::make('in', static fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface => \in_array($suffix, ['title', 'genre'], true)
                ? WhereIn::make('in.' . $suffix, $suffix)->acceptsList()->build()
                : null)
                ->acceptsList(),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withDefaultPerPage(10),
            CursorPaginator::make()->withDefaultSize(2),
        );
    }
}
