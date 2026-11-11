<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `runs` over the store named by `related_batch.store`, `eloquent` or `memory`, and
 * `progresses` and `advertisers` each over a {@see CountingProvider}, which batches unless
 * `related_batch.batches` is false.
 *
 * Seed: run 1 "Alpha" is 40% done and has advertisers 1 "Acme" and 2 "Globex"; run 2 "Beta" is
 * 100% done and has advertiser 3 "Initech"; run 3 "Gamma" has neither.
 *
 * @internal
 */
final class RelatedBatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([RunResource::class, ProgressResource::class, AdvertiserResource::class]);

        $batches = config('related_batch.batches') !== false;
        $progresses = new CountingProvider('progresses', [
            '1' => [new ProgressRecord('p1', 40)],
            '2' => [new ProgressRecord('p2', 100)],
        ], $batches);
        $advertisers = new CountingProvider('advertisers', [
            '1' => [new AdvertiserRecord('1', 'Acme'), new AdvertiserRecord('2', 'Globex')],
            '2' => [new AdvertiserRecord('3', 'Initech')],
        ], $batches);

        JsonApi::provider($progresses);
        JsonApi::provider($advertisers);
        $this->app->instance('related_batch.progresses', $progresses);
        $this->app->instance('related_batch.advertisers', $advertisers);

        if (config('related_batch.store') === 'memory') {
            JsonApi::provider(new InMemoryDataProvider('runs', [
                '1' => new RunRecord('1', 'Alpha'),
                '2' => new RunRecord('2', 'Beta'),
                '3' => new RunRecord('3', 'Gamma'),
            ]));

            return;
        }

        JsonApi::provider(new EloquentDataProvider(['runs' => Run::class]), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
