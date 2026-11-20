<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Curator;
use Workbench\App\Models\Panel;
use Workbench\App\NestedFieldsets\CuratorResource;
use Workbench\App\NestedFieldsets\PanelResource;
use Workbench\App\NestedFieldsets\PinboardResource;

/**
 * The **Eloquent** half of the nested fieldsets conformance wiring: the same resources
 * the in-memory half serves, each object attribute a real `json` column.
 */
final class NestedFieldsetsEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PanelResource::class, CuratorResource::class, PinboardResource::class]);

        $modelByType = ['panels' => Panel::class, 'curators' => Curator::class, 'pinboards' => Panel::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
