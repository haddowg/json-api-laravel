<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Artist;
use Workbench\App\Models\User;
use Workbench\App\Security\ArtistResource;
use Workbench\App\Security\Policies\ArtistApiPolicy;

/**
 * The Eloquent-only wiring for the two **Gate-driven** authorization resolution paths
 * (PLAN decision 7), demonstrated on the secured {@see ArtistResource} which declares no
 * `policy:` attribute:
 *  - {@see Gate::policy()} maps the {@see Artist} model to {@see ArtistApiPolicy}, so the
 *    authorizer resolves `view`/`create`/`update`/`delete` through the **model-registered
 *    policy** automatically (the default path);
 *  - {@see Gate::define()} registers the `browseArtists` ability the resource renames its
 *    list ability to — a name the policy lacks, so the Gate resolves it through this
 *    closure (the **Gate::define** path).
 *
 * Served on the unguarded `default` server, so an unauthenticated request is denied by
 * the policy/gate itself (a `403`), the Laravel-idiomatic guest denial (contrast the
 * auth-guarded `secure` server's `401`).
 */
final class GatePolicyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ArtistResource::class]);

        $modelByType = ['artists' => Artist::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }

    public function boot(): void
    {
        Gate::policy(Artist::class, ArtistApiPolicy::class);

        Gate::define('browseArtists', static fn(User $user): bool => $user->can_write);
    }
}
