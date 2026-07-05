<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\User;
use Workbench\App\Surface\AlbumResource;
use Workbench\App\Surface\ArtistResource;
use Workbench\App\Surface\PublishAlbumAction;

/**
 * The **Eloquent** half of the Phase-4 surface wiring: it registers the SAME writable
 * {@see AlbumResource} / {@see ArtistResource} and {@see PublishAlbumAction} the in-memory
 * half serves, over the reference {@see EloquentDataProvider} / {@see EloquentDataPersister}
 * pair at `-128`. The Eloquent persister nests each atomic sub-op as a savepoint under the
 * batch's outer transaction (ADR 0009 addendum), so the all-or-nothing guarantee holds on
 * real SQL exactly as it does on the in-memory witness.
 */
final class SurfaceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AlbumResource::class, ArtistResource::class, PublishAlbumAction::class]);

        $modelByType = [
            'albums' => Album::class,
            'artists' => Artist::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }

    public function boot(): void
    {
        // The same `publish` ability the in-memory half defines; here the album argument is
        // the Eloquent model — the ability is provider-agnostic.
        Gate::define('publish', static fn(?User $user, object $album): bool => $user?->can_write === true);
    }
}
