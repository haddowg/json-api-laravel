<?php

declare(strict_types=1);

namespace Workbench\App\Cursor;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `cursorWidgets` resource served by BOTH cursor (keyset) conformance concretes.
 * Its `pagination()` returns a {@see MultiPaginator} offering a page-number strategy
 * alongside the cursor (keyset) strategy, defaulting to cursor — so the same endpoint
 * witnesses both client-selectable strategy selection AND the keyset push-down (PLAN
 * decision 9, bundle ADR 0063). Absent a discriminator (or with only the shared
 * `page[size]` key) it resolves to the cursor default, keeping the keyset suite
 * unchanged; `page[kind]=page` (or the page-unique `page[number]`) selects the
 * count-based strategy instead.
 *
 * It lives OUTSIDE `workbench/app/JsonApi` so it is discovered ONLY by the dedicated
 * cursor conformance service providers — the artists/albums/genres suites (and the
 * route-registration feature test) never see it.
 *
 * `id`, `category`, `priority` and `releasedAt` are all sortable: `category` carries
 * ties (so the appended PK tiebreak is exercised), `priority` is a NULLABLE int (the
 * null-branch ground truth), and `releasedAt` is a NULLABLE datetime (the date-keyed,
 * typed-boundary case). No default sort, so a bare request is a PK-only keyset.
 */
#[AsJsonApiResource(readOnly: true)]
final class CursorWidgetResource extends AbstractResource
{
    public static string $type = 'cursorWidgets';

    public function fields(): array
    {
        return [
            // `id` is sortable, so `?sort=…,id` is a valid explicit tiebreak; when
            // `?sort` omits it the keyset appends it anyway.
            Id::make()->sortable(),
            Str::make('category')->sortable(),
            Integer::make('priority')->nullable()->sortable(),
            DateTime::make('releasedAt')->storedAs('released_at')->nullable()->sortable(),
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
