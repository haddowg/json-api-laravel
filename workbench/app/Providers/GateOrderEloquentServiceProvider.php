<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Workbench\App\GateOrder\GateListener;
use Workbench\App\GateOrder\GateWidgetResource;
use Workbench\App\GateOrder\PokeGateWidget;
use Workbench\App\Models\GateWidget;

/**
 * The gate-order suite on the reference Eloquent provider and persister.
 */
final class GateOrderEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GateWidgetResource::class, PokeGateWidget::class]);

        $modelByType = ['gateWidgets' => GateWidget::class];
        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }

    public function boot(Dispatcher $events): void
    {
        GateListener::listen($events);
    }
}
