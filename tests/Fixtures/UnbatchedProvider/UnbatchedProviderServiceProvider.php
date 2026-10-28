<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `advertisers`, `owners` and `campaigns` over the store named by `unbatched.store`,
 * `eloquent` or `memory`, with `advertisers` served by an {@see UnbatchedProvider}.
 *
 * Seed: advertiser 1 "Acme" is owned by owner 1 "Ada" and runs campaigns 1 and 2; advertiser 2
 * "Globex" is owned by owner 2 "Grace" and runs campaign 3.
 *
 * @internal
 */
final class UnbatchedProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AdvertiserResource::class, OwnerResource::class, CampaignResource::class]);

        if (config('unbatched.store') === 'memory') {
            $owners = ['1' => new OwnerRecord('1', 'Ada'), '2' => new OwnerRecord('2', 'Grace')];
            $campaigns = [
                '1' => new CampaignRecord('1', 'Spring'),
                '2' => new CampaignRecord('2', 'Summer'),
                '3' => new CampaignRecord('3', 'Autumn'),
            ];
            $advertisers = [
                '1' => new AdvertiserRecord('1', 'Acme', $owners['1'], [$campaigns['1'], $campaigns['2']]),
                '2' => new AdvertiserRecord('2', 'Globex', $owners['2'], [$campaigns['3']]),
            ];

            JsonApi::provider(new UnbatchedProvider('advertisers', new InMemoryDataProvider('advertisers', $advertisers)));
            JsonApi::provider(new InMemoryDataProvider('owners', $owners));
            JsonApi::provider(new InMemoryDataProvider('campaigns', $campaigns));
            $this->app->instance('unbatched.advertisers', $advertisers);

            return;
        }

        $models = ['advertisers' => Advertiser::class, 'owners' => Owner::class, 'campaigns' => Campaign::class];
        JsonApi::provider(new UnbatchedProvider('advertisers', new EloquentDataProvider($models)));
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
