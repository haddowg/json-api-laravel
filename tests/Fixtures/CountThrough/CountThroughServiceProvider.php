<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CountThrough;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `ctBrands`, `ctTemplates`, `ctCampaigns` and `ctLeads` over the store named by
 * `count_through.store`, `eloquent` or `memory`, both seeded from the same rows.
 *
 * Campaigns and leads both have a `brand`, so a filter tested on the campaign instead of
 * the lead counts the wrong leads. Brands 1 Acme and 2 Globex; templates 1 Welcome and 2
 * Reminder; campaigns 1 Spring for Acme, 2 Summer for Globex and 3 Autumn with no brand.
 *
 * @internal
 */
final class CountThroughServiceProvider extends ServiceProvider
{
    /**
     * @return array<int, string>
     */
    public static function brands(): array
    {
        return [1 => 'Acme', 2 => 'Globex'];
    }

    /**
     * @return array<int, string>
     */
    public static function templates(): array
    {
        return [1 => 'Welcome', 2 => 'Reminder'];
    }

    /**
     * @return list<array{id: int, name: string, brand: int|null}>
     */
    public static function campaigns(): array
    {
        return [
            ['id' => 1, 'name' => 'Spring', 'brand' => 1],
            ['id' => 2, 'name' => 'Summer', 'brand' => 2],
            ['id' => 3, 'name' => 'Autumn', 'brand' => null],
        ];
    }

    /**
     * Spring shortlists leads 2, 4 and 5, Summer lead 1, and Autumn none.
     *
     * @return list<array{id: int, name: string, campaign: int, brand: int|null, templates: list<int>, shortlistedBy: list<int>}>
     */
    public static function leads(): array
    {
        return [
            ['id' => 1, 'name' => 'Ada', 'campaign' => 1, 'brand' => 2, 'templates' => [1], 'shortlistedBy' => [2]],
            ['id' => 2, 'name' => 'Grace', 'campaign' => 1, 'brand' => 1, 'templates' => [1, 2], 'shortlistedBy' => [1]],
            ['id' => 3, 'name' => 'Hedy', 'campaign' => 1, 'brand' => null, 'templates' => [], 'shortlistedBy' => []],
            ['id' => 4, 'name' => 'Joan', 'campaign' => 2, 'brand' => 2, 'templates' => [2], 'shortlistedBy' => [1]],
            ['id' => 5, 'name' => 'Katherine', 'campaign' => 3, 'brand' => 1, 'templates' => [1], 'shortlistedBy' => [1]],
            ['id' => 6, 'name' => 'Lin', 'campaign' => 3, 'brand' => null, 'templates' => [], 'shortlistedBy' => []],
        ];
    }

    public function register(): void
    {
        JsonApi::register([BrandResource::class, TemplateResource::class, CampaignResource::class, LeadResource::class]);

        if (config('count_through.store') !== 'memory') {
            JsonApi::provider(new EloquentDataProvider([
                'ctBrands' => Brand::class,
                'ctTemplates' => Template::class,
                'ctCampaigns' => Campaign::class,
                'ctLeads' => Lead::class,
            ]), priority: -128);

            return;
        }

        $brands = [];
        foreach (self::brands() as $id => $name) {
            $brands[$id] = new BrandRecord((string) $id, $name);
        }
        $templates = [];
        foreach (self::templates() as $id => $name) {
            $templates[$id] = new TemplateRecord((string) $id, $name);
        }
        $campaigns = [];
        foreach (self::campaigns() as $row) {
            $campaigns[$row['id']] = new CampaignRecord((string) $row['id'], $row['name'], $row['brand'] !== null ? $brands[$row['brand']] : null);
        }
        $leads = [];
        foreach (self::leads() as $row) {
            $campaign = $campaigns[$row['campaign']];
            $lead = new LeadRecord(
                (string) $row['id'],
                $row['name'],
                $campaign,
                $row['brand'] !== null ? $brands[$row['brand']] : null,
                \array_map(static fn(int $id): TemplateRecord => $templates[$id], $row['templates']),
            );
            $campaign->leads[] = $lead;
            foreach ($row['shortlistedBy'] as $shortlister) {
                $campaigns[$shortlister]->shortlist[] = $lead;
            }
            $leads[$lead->id] = $lead;
        }

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $keyed = static function (array $records): array {
            $out = [];
            foreach ($records as $record) {
                $out[$record->id] = $record;
            }

            return $out;
        };

        JsonApi::provider(new InMemoryDataProvider('ctBrands', $keyed($brands), identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('ctTemplates', $keyed($templates), identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('ctCampaigns', $keyed($campaigns), identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('ctLeads', $leads, identify: $identify));
    }
}
