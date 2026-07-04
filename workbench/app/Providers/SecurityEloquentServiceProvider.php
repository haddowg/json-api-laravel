<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Album;
use Workbench\App\Models\Genre;
use Workbench\App\Security\AlbumResource;
use Workbench\App\Security\GenreResource;

/**
 * The **Eloquent** half of the authorization conformance wiring (PLAN decision 7): it
 * registers the SAME secured {@see AlbumResource} (dedicated `AlbumApiPolicy`) and
 * policy-less {@see GenreResource} the in-memory half serves, over the reference
 * {@see EloquentDataProvider} / {@see EloquentDataPersister} pair at `-128`. The
 * dedicated policy authorizes the Eloquent model exactly as it authorizes the in-memory
 * POPO, so the dual-provider authorization assertions run identically on real SQL.
 */
final class SecurityEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AlbumResource::class, GenreResource::class]);

        $modelByType = [
            'albums' => Album::class,
            'genres' => Genre::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
