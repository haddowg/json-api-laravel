<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\FieldSelection\CuratorResource;
use Workbench\App\FieldSelection\PanelResource;
use Workbench\App\FieldSelection\PinboardResource;
use Workbench\App\Models\Curator;
use Workbench\App\Models\Panel;

/**
 * The **Eloquent** half of the field-selection conformance wiring: the same resources
 * the in-memory half serves, each object attribute a real `json` column.
 */
final class FieldSelectionEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PanelResource::class, CuratorResource::class, PinboardResource::class]);

        $modelByType = ['panels' => Panel::class, 'curators' => Curator::class, 'pinboards' => Panel::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
