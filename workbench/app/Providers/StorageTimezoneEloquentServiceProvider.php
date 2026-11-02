<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\StorageTimezone\Models\Shift;
use Workbench\App\StorageTimezone\ShiftResource;

/**
 * Wires the shifts resource to the Eloquent provider and persister.
 */
final class StorageTimezoneEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShiftResource::class]);

        JsonApi::provider(new EloquentDataProvider(['shifts' => Shift::class]), priority: -128);
        JsonApi::persister(new EloquentDataPersister(['shifts' => Shift::class]), priority: -128);
    }
}
