<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the constructor-dependency fixture: binds {@see Clock} to a {@see FixedClock},
 * registers {@see ClockStampResource} explicitly (no filesystem scan needed), and seeds
 * its in-memory provider. Everything runs in `register()` so it lands before the package
 * provider's `boot()` reads the discovery + provider registrations.
 *
 * @internal
 */
final class ContainerResourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Clock::class, FixedClock::class);

        JsonApi::register([ClockStampResource::class]);
        JsonApi::provider(new InMemoryDataProvider('clock-stamps', ['1' => new ClockStamp('1')]));
    }
}
