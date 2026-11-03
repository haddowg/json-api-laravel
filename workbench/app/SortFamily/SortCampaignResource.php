<?php

declare(strict_types=1);

namespace Workbench\App\SortFamily;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `sortCampaigns` resource served by both sort-family conformance concretes. Two
 * families sit beside the fixed `id`/`name`/`team` keys: `attr.<field>` resolves to a
 * {@see SortByField} over one of the campaign's own columns, and `value.<placeholder>`
 * to a {@see PlaceholderValueSort} over a joined placeholder value. Pagination offers
 * page and cursor strategies, defaulting to cursor.
 */
#[AsJsonApiResource(readOnly: true)]
final class SortCampaignResource extends AbstractResource
{
    public static string $type = 'sortCampaigns';

    private const array COLUMNS = ['name', 'team'];

    private const array PLACEHOLDERS = ['cap', 'reach'];

    public function fields(): array
    {
        return [
            Id::make()->sortable(),
            Str::make('name')->sortable(),
            Str::make('team')->sortable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortFamily::make('attr', static fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface => \in_array($suffix, self::COLUMNS, true)
                ? SortByField::make('attr.' . $suffix, $suffix)
                : null)
                ->withSuffixPattern('[a-z]+'),
            SortFamily::make('value', static fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface => \in_array($suffix, self::PLACEHOLDERS, true)
                ? new PlaceholderValueSort('value.' . $suffix, $suffix)
                : null),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withDefaultPerPage(2),
            CursorPaginator::make()->withDefaultSize(2),
        )->default('cursor');
    }
}
