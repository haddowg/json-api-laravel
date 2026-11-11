<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the misdeclared opt-out without its routes, so a test can register them.
 */
final class MisdeclaredFilterDefaultsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([MisdeclaredShelfResource::class, BookResource::class])->ignoreRoutes();

        JsonApi::provider(new InMemoryDataProvider('shelves', []));
        JsonApi::provider(new InMemoryDataProvider('books', []));
    }
}
