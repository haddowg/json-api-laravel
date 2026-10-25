<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedIds;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Exposes `parcels` on `default` with plain ids and on `admin` with prefixed ids, through the
 * reference Eloquent pair. The `default` class registers first.
 *
 * @internal
 */
final class ServerScopedIdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ParcelResource::class, AdminParcelResource::class]);

        $models = ['parcels' => Parcel::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }
}
