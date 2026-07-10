<?php

declare(strict_types=1);

namespace Workbench\App\CursorPivot;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The `cursorBoards` parent resource for the PIVOT-bearing related-collection cursor
 * (keyset) conformance suite: its to-many `widgets` relation is a `belongsToMany`
 * carrying a declared pivot field (`position`, read-only) AND its own
 * {@see CursorPaginator} (default size 2), so `GET /cursorBoards/{id}/widgets`
 * resolves a keyset window over the pivot-joined relation and renders each member's
 * stored pivot as `meta.pivot` on the SAME cursor page (docs/adr/0017). The keyset
 * vocabulary is the widget resource's own sortable fields
 * ({@see \Workbench\App\Cursor\CursorWidgetResource}), so the pivot walk exercises
 * the identical branches the plain related-cursor suite does — with the join in play.
 *
 * It lives OUTSIDE `workbench/app/Cursor` so the primary/related cursor conformance
 * concretes (and the music-catalog suites) never see it — only the dedicated
 * pivot-cursor service providers discover this directory (alongside `Cursor`).
 */
#[AsJsonApiResource(readOnly: true)]
final class CursorBoardResource extends AbstractResource
{
    public static string $type = 'cursorBoards';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name'),
            BelongsToMany::make('widgets', 'cursorWidgets')
                ->fields(Integer::make('position')->readOnly()->build())
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
