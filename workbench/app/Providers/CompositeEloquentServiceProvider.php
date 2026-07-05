<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\CompositeWidget;
use Workbench\App\Validation\CompositeWidgetResource;

/**
 * The **Eloquent** half of the composite-attribute conformance wiring: the SAME
 * {@see CompositeWidgetResource} the in-memory half serves, over the reference
 * Eloquent provider/persister pair at `-128` — so each composite attribute
 * round-trips a real `json` column on {@see CompositeWidget}, not just the in-memory
 * array.
 */
final class CompositeEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CompositeWidgetResource::class]);

        $modelByType = ['composites' => CompositeWidget::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
