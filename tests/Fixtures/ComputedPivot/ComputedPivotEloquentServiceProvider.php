<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ComputedPivot;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Playlist;

/**
 * Registers {@see CrateResource} over the workbench `Playlist` model, beside the Eloquent
 * workbench wiring that serves `tracks`.
 *
 * @internal
 */
final class ComputedPivotEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CrateResource::class]);

        $models = ['crates' => Playlist::class];
        JsonApi::provider(new EloquentDataProvider($models));
        JsonApi::persister(new EloquentDataPersister($models));
    }
}
