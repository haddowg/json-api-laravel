<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\StorageTimezone\EloquentShiftResource;
use Workbench\App\StorageTimezone\Models\Shift;
use Workbench\App\StorageTimezone\Models\Worker;
use Workbench\App\StorageTimezone\WorkerResource;

/**
 * Wires the shifts resource to the Eloquent provider and persister.
 */
final class StorageTimezoneEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([EloquentShiftResource::class, WorkerResource::class]);

        $modelByType = ['shifts' => Shift::class, 'workers' => Worker::class];
        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
