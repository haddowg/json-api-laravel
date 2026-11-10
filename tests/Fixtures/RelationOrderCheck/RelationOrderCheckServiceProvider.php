<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the misdeclared read order fixture without its routes, so a test can register them.
 */
final class RelationOrderCheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([LedgerResource::class, EntryResource::class])->ignoreRoutes();

        JsonApi::provider(new InMemoryDataProvider('ledgers', []));
        JsonApi::provider(new InMemoryDataProvider('entries', []));
    }
}
