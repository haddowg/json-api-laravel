<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionCreated;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `orders` over an in-memory store with two actions declaring a `201`. The status is
 * decided by core's dispatch over the declared responses, which no data layer takes part
 * in, so there is no Eloquent twin.
 *
 * @internal
 */
final class ActionCreatedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([OrderResource::class, DuplicateOrderAction::class, RestoreOrderAction::class]);
        JsonApi::provider(new InMemoryDataProvider(
            'orders',
            ['1' => new OrderRecord('1', 'A-1'), '2' => new OrderRecord('2', 'A-2')],
            identify: static fn(object $order): string => $order instanceof OrderRecord ? $order->id : '',
        ));
    }

    public function boot(): void
    {
        Gate::policy(OrderRecord::class, OrderPolicy::class);
    }
}
