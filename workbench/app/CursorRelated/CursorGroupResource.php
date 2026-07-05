<?php

declare(strict_types=1);

namespace Workbench\App\CursorRelated;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `cursorGroups` parent resource for the RELATED-collection cursor (keyset)
 * conformance suite: its to-many `widgets` relation declares its OWN
 * {@see CursorPaginator} (default size 2), so `GET /cursorGroups/{id}/widgets`
 * resolves a keyset window and both providers run the parent-scoped keyset
 * execution (docs/adr/0015). The related keyset vocabulary is the widget
 * resource's own sortable fields ({@see \Workbench\App\Cursor\CursorWidgetResource}
 * — `category` ties, a NULLABLE `priority`, a NULLABLE `releasedAt` datetime), so
 * the parent-scoped walk exercises the same branches the primary suite does.
 *
 * It lives OUTSIDE `workbench/app/Cursor` so the primary cursor conformance
 * concretes (and the music-catalog suites) never see it — only the dedicated
 * related-cursor service providers discover this directory (alongside `Cursor`).
 */
#[AsJsonApiResource(readOnly: true)]
final class CursorGroupResource extends AbstractResource
{
    public static string $type = 'cursorGroups';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('widgets', 'cursorWidgets')
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
