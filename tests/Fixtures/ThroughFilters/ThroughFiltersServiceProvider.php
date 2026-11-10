<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `advertisers`, `brands`, `templates`, `campaigns` and `leads` over the store named by
 * `through_filters.store`, `eloquent` or `memory`, both seeded from the same rows.
 *
 * @internal
 */
final class ThroughFiltersServiceProvider extends ServiceProvider
{
    public const string BRAND_NORTH = '7f3c2a1e-4b5d-4c6e-8f90-a1b2c3d4e5f1';

    public const string BRAND_SOUTH = '7f3c2a1e-4b5d-4c6e-8f90-a1b2c3d4e5f2';

    /**
     * @return array<int, string>
     */
    public static function advertisers(): array
    {
        return [1 => 'Acme', 2 => 'Globex', 3 => 'Initech'];
    }

    /**
     * @return array<string, string>
     */
    public static function brands(): array
    {
        return [self::BRAND_NORTH => 'North', self::BRAND_SOUTH => 'South'];
    }

    /**
     * @return array<int, string>
     */
    public static function templates(): array
    {
        return [1 => 'Welcome', 2 => 'Reminder', 3 => 'Offer'];
    }

    /**
     * Campaign 1 Spring is Acme's under North, 2 Summer Globex's under South and 3 "Autumn,
     * late" Initech's under North.
     *
     * @return list<array{id: int, name: string, advertiser: int, brand: string}>
     */
    public static function campaigns(): array
    {
        return [
            ['id' => 1, 'name' => 'Spring', 'advertiser' => 1, 'brand' => self::BRAND_NORTH],
            ['id' => 2, 'name' => 'Summer', 'advertiser' => 2, 'brand' => self::BRAND_SOUTH],
            ['id' => 3, 'name' => 'Autumn, late', 'advertiser' => 3, 'brand' => self::BRAND_NORTH],
        ];
    }

    /**
     * @return list<array{id: int, status: string, campaign: int, templates: list<int>}>
     */
    public static function leads(): array
    {
        return [
            ['id' => 1, 'status' => 'open', 'campaign' => 1, 'templates' => [1]],
            ['id' => 2, 'status' => 'closed', 'campaign' => 1, 'templates' => [2, 3]],
            ['id' => 3, 'status' => 'open', 'campaign' => 2, 'templates' => [3]],
            ['id' => 4, 'status' => 'open', 'campaign' => 3, 'templates' => []],
            ['id' => 5, 'status' => 'closed', 'campaign' => 2, 'templates' => [1, 2]],
        ];
    }

    public function register(): void
    {
        JsonApi::register([AdvertiserResource::class, BrandResource::class, TemplateResource::class, CampaignResource::class, LeadResource::class]);

        if (config('through_filters.store') !== 'memory') {
            JsonApi::provider(new EloquentDataProvider([
                'advertisers' => Advertiser::class,
                'brands' => Brand::class,
                'templates' => Template::class,
                'campaigns' => Campaign::class,
                'leads' => Lead::class,
            ]), priority: -128);

            return;
        }

        $advertisers = [];
        foreach (self::advertisers() as $id => $name) {
            $advertisers[(string) $id] = new AdvertiserRecord((string) $id, $name);
        }
        $brands = [];
        foreach (self::brands() as $id => $name) {
            $brands[$id] = new BrandRecord($id, $name);
        }
        $templates = [];
        foreach (self::templates() as $id => $name) {
            $templates[(string) $id] = new TemplateRecord((string) $id, $name);
        }
        $campaigns = [];
        foreach (self::campaigns() as $row) {
            $campaigns[(string) $row['id']] = new CampaignRecord((string) $row['id'], $row['name'], $advertisers[(string) $row['advertiser']], $brands[$row['brand']]);
        }
        $leads = [];
        foreach (self::leads() as $row) {
            $campaign = $campaigns[(string) $row['campaign']];
            $lead = new LeadRecord((string) $row['id'], $row['status'], $campaign, \array_map(static fn(int $id): TemplateRecord => $templates[(string) $id], $row['templates']));
            $campaign->leads[] = $lead;
            $leads[$lead->id] = $lead;
        }

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };

        JsonApi::provider(new InMemoryDataProvider('advertisers', $advertisers, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('brands', $brands, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('templates', $templates, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('campaigns', $campaigns, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('leads', $leads, identify: $identify));
    }
}
