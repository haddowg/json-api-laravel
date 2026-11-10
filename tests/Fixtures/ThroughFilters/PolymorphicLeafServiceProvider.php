<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers {@see NoteResource} and {@see BoardResource}, whose filters end on a polymorphic
 * relation, over empty in-memory stores.
 *
 * @internal
 */
final class PolymorphicLeafServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([AdvertiserResource::class, BrandResource::class, NoteResource::class, BoardResource::class])->ignoreRoutes();

        foreach (['advertisers', 'brands', 'notes', 'boards'] as $type) {
            JsonApi::provider(new InMemoryDataProvider($type, []));
        }
    }
}
