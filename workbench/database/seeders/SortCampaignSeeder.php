<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\SortCampaign;
use Workbench\App\Models\SortCampaignValue;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the shared {@see ConformanceFixtures::sortCampaigns()} rows and their placeholder
 * values, the same data the in-memory sort-family provider carries.
 */
final class SortCampaignSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConformanceFixtures::sortCampaigns() as $row) {
            SortCampaign::query()->create(['id' => $row['id'], 'name' => $row['name'], 'team' => $row['team']]);

            foreach ($row['values'] as $name => $value) {
                SortCampaignValue::query()->create([
                    'sort_campaign_id' => $row['id'],
                    'name' => $name,
                    'value' => (string) $value,
                ]);
            }
        }
    }
}
