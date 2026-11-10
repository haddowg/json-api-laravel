<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Preset;
use Workbench\App\Union\PresetResource;

/**
 * The **Eloquent** half of the union conformance wiring: the same {@see PresetResource}
 * the in-memory half serves, over the reference Eloquent provider/persister pair.
 */
final class UnionEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PresetResource::class]);
        $modelByType = ['presets' => Preset::class];
        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
