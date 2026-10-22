<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Timezone\GigResource;
use Workbench\App\Timezone\Models\Gig;
use Workbench\App\Timezone\Models\Performer;
use Workbench\App\Timezone\Models\Venue;
use Workbench\App\Timezone\PerformerResource;
use Workbench\App\Timezone\VenueResource;

/**
 * The Eloquent half of the output-timezone conformance wiring, read-only.
 */
final class TimezoneEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GigResource::class, VenueResource::class, PerformerResource::class]);

        JsonApi::provider(new EloquentDataProvider([
            'gigs' => Gig::class,
            'venues' => Venue::class,
            'performers' => Performer::class,
        ]), priority: -128);
    }
}
