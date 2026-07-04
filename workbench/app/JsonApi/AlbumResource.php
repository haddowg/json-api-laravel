<?php

declare(strict_types=1);

namespace Workbench\App\JsonApi;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Boolean as BooleanFilter;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\EndsWith;
use haddowg\JsonApi\Resource\Filter\StartsWith;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `albums` resource type, re-themed from the Symfony bundle's example and shared by
 * BOTH provider suites. Its camelCase attributes map to snake_case storage columns via
 * `storedAs()` (`averageRating` → `average_rating`, `availableFrom` → `available_from`,
 * `releasedAt` → `released_at`).
 *
 * Phase 1 enriches it with the full filter vocabulary (substring title, date range,
 * boolean, id-set on status, null presence on the nullable rating), a
 * `releasedAt`-descending default sort, and a
 * **counting** page paginator (`withCount()`) — so every album collection renders
 * `meta.page.total` + a `last` link (the counted pagination arm), the counterpart to
 * the count-free `artists` collection.
 */
#[AsJsonApiResource(readOnly: true)]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Decimal::make('averageRating')->storedAs('average_rating')->readOnly()->nullable(),
            Str::make('status')->sortable(),
            Boolean::make('explicit'),
            Date::make('availableFrom')->storedAs('available_from')->nullable(),
            DateTime::make('releasedAt')->storedAs('released_at')->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            // Case-insensitive substring / prefix / suffix search over the title.
            Contains::make('title'),
            StartsWith::make('titleStarts', 'title'),
            EndsWith::make('titleEnds', 'title'),
            // Structured date-time range over the NON-NULL `released_at` column (ISO-8601
            // bounds). Ordered comparison / range filters are declared only over columns
            // with no null rows — the witness coerces `null` in a comparison while SQL
            // three-valued logic excludes it, so ranging a null-bearing column would
            // diverge (see comparisonFiltersOverNullableColumns…). The bundle sidesteps
            // the same way; null presence is tested with WhereNull/WhereNotNull instead.
            DateRange::make('releasedRange', 'released_at'),
            // Coerced boolean equality on `explicit`.
            BooleanFilter::make('explicit'),
            // Set membership (and its negation) on `status`.
            WhereIn::make('status'),
            WhereNotIn::make('statusNot', 'status'),
            // Null presence on the nullable `average_rating` column.
            WhereNull::make('unrated', 'average_rating'),
            WhereNotNull::make('rated', 'average_rating'),
        ];
    }

    /**
     * Newest first by default: with no `?sort`, order by `released_at` descending (so OK
     * Computer (1997) precedes Dummy (1994)). An explicit `?sort=` overrides it entirely.
     *
     * @return list<SortDirective>
     */
    public function defaultSort(): array
    {
        return [
            new SortDirective(SortByField::make('releasedAt', 'released_at'), descending: true),
        ];
    }

    /**
     * A **counting** page paginator: `withCount()` runs the pre-window `COUNT` on every
     * paged request, so `meta.page.total` and the `last` link are always present — the
     * counted arm (contrast the count-free `artists` collection on the server default).
     */
    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return PagePaginator::make()->withCount();
    }
}
