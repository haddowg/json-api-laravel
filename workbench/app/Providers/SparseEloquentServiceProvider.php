<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\SparseWidget;
use Workbench\App\Sparse\SparseWidgetResource;

/**
 * The **Eloquent** half of the sparse-by-default conformance wiring: the SAME
 * {@see SparseWidgetResource} the in-memory half serves, over the reference Eloquent
 * provider at `-128` — so the sparse-by-default `expensiveScore` attribute is read off
 * a real `expensive_score` column on {@see SparseWidget}, not just an in-memory array.
 * Read-only: no persister is wired, matching the witness's fetch-only assertions.
 */
final class SparseEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([SparseWidgetResource::class]);

        JsonApi::provider(new EloquentDataProvider(['sparseWidgets' => SparseWidget::class]), priority: -128);
    }
}
