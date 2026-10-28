<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `campaigns`, whose `target` MorphTo is identified by two plain columns, over the store
 * named by `identified.store`: `eloquent`, where the {@see Advertiser} model carries the
 * {@see HidesHiddenAdvertisers} global scope, or `memory`, where {@see VisibleAdvertisersOnly}
 * owns `advertisers`.
 *
 * Seed: people 1 Ada and 2 Bob; advertisers 1 Acme owned by Ada, 2 Globex owned by Bob and 3
 * Shadow owned by Ada, which is hidden; agencies 1 Initech owned by
 * Bob and 2 Umbrella owned by Ada. Ten campaigns:
 *
 *  1 advertiser 1, 2 agency 1, 3 advertiser 2, 4 none, 5 advertiser 3 (hidden),
 *  6 agency 2, 7 advertiser 1, 8 agency 1, 9 advertiser 2, 10 agency 2.
 *
 * @internal
 */
final class IdentifiedMorphServiceProvider extends ServiceProvider
{
    /**
     * The seeded campaigns' targets as stored, by campaign id.
     */
    public const array CAMPAIGNS = [
        1 => ['advertiser', 1],
        2 => ['agency', 1],
        3 => ['advertiser', 2],
        4 => [null, null],
        5 => ['advertiser', 3],
        6 => ['agency', 2],
        7 => ['advertiser', 1],
        8 => ['agency', 1],
        9 => ['advertiser', 2],
        10 => ['agency', 2],
    ];

    public function register(): void
    {
        JsonApi::register([CampaignResource::class, AdvertiserResource::class, AgencyResource::class, PersonResource::class]);

        if (config('identified.store') === 'memory') {
            $people = ['1' => new PersonRecord('1', 'Ada'), '2' => new PersonRecord('2', 'Bob')];
            $advertisers = [
                '1' => new AdvertiserRecord('1', 'Acme', $people['1']),
                '2' => new AdvertiserRecord('2', 'Globex', $people['2']),
                '3' => new AdvertiserRecord('3', 'Shadow', $people['1'], hidden: true),
            ];
            $agencies = ['1' => new AgencyRecord('1', 'Initech', $people['2']), '2' => new AgencyRecord('2', 'Umbrella', $people['1'])];
            $campaigns = [];
            foreach (self::CAMPAIGNS as $id => [$type, $target]) {
                $campaigns[(string) $id] = new CampaignRecord((string) $id, 'Campaign ' . $id, $type, $target === null ? null : (string) $target);
            }

            $identify = static fn(object $row): string => $row instanceof CampaignRecord ? $row->id : '';
            $campaignProvider = new InMemoryDataProvider('campaigns', $campaigns, identify: $identify);
            $this->app->instance('identified.campaigns', $campaignProvider);

            JsonApi::provider($campaignProvider);
            JsonApi::provider(new VisibleAdvertisersOnly($advertisers));
            JsonApi::provider(new InMemoryDataProvider('agencies', $agencies, identify: static fn(object $row): string => $row instanceof AgencyRecord ? $row->id : ''));
            JsonApi::provider(new InMemoryDataProvider('people', $people));

            return;
        }

        $models = ['campaigns' => Campaign::class, 'advertisers' => Advertiser::class, 'agencies' => Agency::class, 'people' => Person::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
