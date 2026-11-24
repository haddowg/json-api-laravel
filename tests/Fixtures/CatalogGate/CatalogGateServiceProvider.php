<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CatalogGate;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Serves hubs and the spokes and rims they relate to from memory. Hub 1, owned by ada, holds
 * spoke 1 and rim 1 and leads with spoke 1; owned hub 1 is ada's too.
 *
 * @internal
 */
final class CatalogGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([HubResource::class, OwnedHubResource::class, SpokeResource::class, RimResource::class]);

        $spoke = new Spoke('1', 'front');
        $rim = new Rim('1', 'outer');
        $identify = static fn(object $row): string => \property_exists($row, 'id') && \is_string($row->id) ? $row->id : '';
        JsonApi::provider(new InMemoryDataProvider('hubs', ['1' => new Hub('1', 'ada', [$spoke], [$rim], $spoke)], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('ownedHubs', ['1' => new Hub('1', 'ada', [$spoke])], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('spokes', ['1' => $spoke], identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('rims', ['1' => $rim], identify: $identify));
    }
}
