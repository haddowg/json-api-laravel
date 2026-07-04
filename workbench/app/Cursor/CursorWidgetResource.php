<?php

declare(strict_types=1);

namespace Workbench\App\Cursor;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `cursorWidgets` resource served by BOTH cursor (keyset) conformance concretes.
 * Its `pagination()` returns a {@see CursorPaginator} (default size 2), so the handler
 * resolves a keyset window and the providers run the keyset push-down — the HTTP arm
 * of the cursor referee (PLAN decision 9, bundle ADR 0063).
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
        return CursorPaginator::make()->withDefaultSize(2);
    }
}
