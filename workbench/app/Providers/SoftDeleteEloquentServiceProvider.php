<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Document;
use Workbench\App\SoftDelete\DocumentResource;
use Workbench\App\SoftDelete\Policies\DocumentPolicy;

/**
 * Wires the first-class soft-delete showcase (Model B): the {@see DocumentResource} — which
 * declares `softDeletes: true`, synthesizing its `restore`/`force-delete` actions — over the
 * reference {@see EloquentDataProvider}/{@see EloquentDataPersister} pair (the persister is
 * {@see \haddowg\JsonApiLaravel\DataPersister\SoftDeleteCapable}, the provider
 * {@see \haddowg\JsonApiLaravel\DataProvider\FetchesTrashed}).
 *
 * {@see Gate::policy()} maps {@see Document} to {@see DocumentPolicy}, so the synthesized
 * `restore`/`forceDelete` abilities dispatch to that policy's native `restore()`/`forceDelete()`
 * methods — the idiomatic Laravel soft-delete authorization the package inherits for free.
 */
final class SoftDeleteEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([DocumentResource::class]);

        $modelByType = ['documents' => Document::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }

    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
