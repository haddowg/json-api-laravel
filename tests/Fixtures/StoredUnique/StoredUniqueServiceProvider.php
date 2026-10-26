<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\StoredUnique;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class StoredUniqueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CouponResource::class]);

        JsonApi::provider(new EloquentDataProvider(['coupons' => Coupon::class]), priority: -128);
        JsonApi::persister(new EloquentDataPersister(['coupons' => Coupon::class]), priority: -128);
    }
}
