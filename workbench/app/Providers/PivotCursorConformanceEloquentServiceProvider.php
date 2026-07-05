<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\CursorBoard;
use Workbench\App\Models\CursorWidget;

/**
 * The **Eloquent** half of the PIVOT-bearing related-collection cursor (keyset)
 * conformance wiring: it discovers the SAME isolated cursor resources the in-memory
 * {@see PivotCursorConformanceInMemoryServiceProvider} uses and registers the
 * reference {@see EloquentDataProvider} at `-128` over a `type → model` map covering
 * the parent AND the related type — so `GET /cursorBoards/{id}/widgets` runs the
 * keyset push-down on the belongsToMany's pivot-joined query (and the handler's
 * `meta.pivot` wrap reads the join's stored `position`), and every inherited assertion
 * must produce the IDENTICAL page the witness renders (docs/adr/0017).
 */
final class PivotCursorConformanceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor', \dirname(__DIR__) . '/CursorPivot']);

        JsonApi::provider(
            new EloquentDataProvider([
                'cursorWidgets' => CursorWidget::class,
                'cursorBoards' => CursorBoard::class,
            ]),
            priority: -128,
        );
    }
}
