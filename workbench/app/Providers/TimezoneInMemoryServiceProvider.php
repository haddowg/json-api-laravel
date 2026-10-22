<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Timezone\Domain\Gig;
use Workbench\App\Timezone\Domain\Performer;
use Workbench\App\Timezone\Domain\Venue;
use Workbench\App\Timezone\GigResource;
use Workbench\App\Timezone\PerformerResource;
use Workbench\App\Timezone\RescheduleGig;
use Workbench\App\Timezone\TimezoneFixtures;
use Workbench\App\Timezone\VenueResource;

/**
 * The in-memory half of the output-timezone conformance wiring, seeded from the same
 * {@see TimezoneFixtures} the Eloquent half stores.
 */
final class TimezoneInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GigResource::class, VenueResource::class, PerformerResource::class, RescheduleGig::class]);

        $venues = [];
        foreach (TimezoneFixtures::venues() as $row) {
            $venues[(string) $row['id']] = new Venue((string) $row['id'], $row['name'], $row['opened_at']);
        }

        $performers = [];
        foreach (TimezoneFixtures::performers() as $row) {
            $performers[(string) $row['id']] = new Performer((string) $row['id'], $row['name']);
        }

        $gigs = [];
        foreach (TimezoneFixtures::gigs() as $row) {
            $gigs[(string) $row['id']] = new Gig(
                id: (string) $row['id'],
                title: $row['title'],
                starts_at: $row['starts_at'],
                day: $row['day'],
                doors_open: $row['doors_open'],
                venue: $venues[(string) $row['venue_id']] ?? null,
            );
        }

        foreach (TimezoneFixtures::bookings() as $row) {
            $gig = $gigs[(string) $row['gig_id']] ?? null;
            $performer = $performers[(string) $row['performer_id']] ?? null;
            if ($gig !== null && $performer !== null) {
                $gig->performers[] = $performer;
            }
        }

        JsonApi::provider(new InMemoryDataProvider('gigs', $gigs));
        JsonApi::provider(new InMemoryDataProvider('venues', $venues));
        JsonApi::provider(new InMemoryDataProvider('performers', $performers));
    }
}
