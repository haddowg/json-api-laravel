<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Sheet;
use Workbench\App\Validation\OpenObjectSheetResource;

/**
 * The **Eloquent** half of the open-object conformance wiring: the same
 * {@see OpenObjectSheetResource} the in-memory half serves, over the reference Eloquent
 * provider/persister pair, so each open object round-trips a real `json` column.
 */
final class OpenObjectEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([OpenObjectSheetResource::class]);

        $modelByType = ['sheets' => Sheet::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
