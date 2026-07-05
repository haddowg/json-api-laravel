<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\CursorGroup;
use Workbench\App\Models\CursorWidget;

/**
 * The **Eloquent** half of the RELATED-collection cursor (keyset) conformance wiring:
 * it discovers the SAME isolated cursor resources the in-memory
 * {@see RelatedCursorConformanceInMemoryServiceProvider} uses and registers the
 * reference {@see EloquentDataProvider} at `-128` over a `type → model` map covering
 * the parent AND the related type — so `GET /cursorGroups/{id}/widgets` runs the
 * keyset push-down on the HasMany's parent-scoped query and every inherited assertion
 * must produce the IDENTICAL page the witness renders (docs/adr/0016).
 */
final class RelatedCursorConformanceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor', \dirname(__DIR__) . '/CursorRelated']);

        JsonApi::provider(
            new EloquentDataProvider([
                'cursorWidgets' => CursorWidget::class,
                'cursorGroups' => CursorGroup::class,
            ]),
            priority: -128,
        );
    }
}
