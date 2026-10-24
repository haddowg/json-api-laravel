<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RestrictedPivot;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Playlist;

/**
 * Registers {@see MixtapeResource} over the workbench `Playlist` model, beside the Eloquent
 * workbench wiring that serves `tracks`.
 *
 * @internal
 */
final class RestrictedPivotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([MixtapeResource::class]);

        $models = ['mixtapes' => Playlist::class];
        JsonApi::provider(new EloquentDataProvider($models));
        JsonApi::persister(new EloquentDataPersister($models));
    }
}
