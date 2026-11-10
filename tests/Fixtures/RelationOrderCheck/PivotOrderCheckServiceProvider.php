<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires a pivot-ordered relation over in-memory providers, which hold no pivot rows, without
 * its routes, so a test can register them.
 */
final class PivotOrderCheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([RackResource::class, EntryResource::class])->ignoreRoutes();

        JsonApi::provider(new InMemoryDataProvider('racks', []));
        JsonApi::provider(new InMemoryDataProvider('entries', []));
    }
}
