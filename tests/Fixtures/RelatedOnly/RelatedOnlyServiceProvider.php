<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires full-CRUD `records` over an in-memory store, each on a related-only `imprints`
 * type that has no provider of its own. Routing and link rendering take no part in the
 * data layer, so there is no Eloquent twin.
 *
 * @internal
 */
final class RelatedOnlyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([RecordResource::class, ImprintResource::class, ReimprintRecordAction::class]);
        $provider = new InMemoryDataProvider(
            'records',
            ['r1' => new Record('r1', 'Debut', new Imprint('i1', 'Island', new Imprint('i0', 'Parent')))],
            identify: static fn(object $record): string => $record instanceof Record ? $record->id : '',
        );
        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('records', $provider->store(), static fn(): Record => new Record()));
    }
}
