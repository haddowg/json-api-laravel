<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\CursorWidget;

/**
 * The **Eloquent** half of the cursor (keyset) conformance wiring: it discovers the SAME
 * isolated {@see \Workbench\App\Cursor\CursorWidgetResource} the in-memory
 * {@see CursorConformanceInMemoryServiceProvider} uses and registers the reference
 * {@see EloquentDataProvider} at `-128` over a `type → model` map — so every assertion
 * inherited from the abstract cursor suite must produce the IDENTICAL keyset page,
 * refereeing the SQL push-down against the witness (PLAN decision 9).
 */
final class CursorConformanceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor']);

        JsonApi::provider(
            new EloquentDataProvider(['cursorWidgets' => CursorWidget::class]),
            priority: -128,
        );
    }
}
