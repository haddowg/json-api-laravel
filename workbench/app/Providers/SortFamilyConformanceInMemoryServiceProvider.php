<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\SortCampaign;
use Workbench\App\SortFamily\PlaceholderValueArrayArm;
use Workbench\App\Support\ConformanceFixtures;

/**
 * The in-memory half of the sort-family conformance wiring, seeded from
 * {@see ConformanceFixtures::sortCampaigns()}: the witness the Eloquent provider must match.
 */
final class SortFamilyConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $campaigns = [];
        foreach (ConformanceFixtures::sortCampaigns() as $row) {
            $campaigns[(string) $row['id']] = new SortCampaign((string) $row['id'], $row['name'], $row['team'], $row['values']);
        }

        JsonApi::discover([\dirname(__DIR__) . '/SortFamily']);

        JsonApi::provider(new InMemoryDataProvider('sortCampaigns', $campaigns, sortArms: [new PlaceholderValueArrayArm()]));
    }
}
