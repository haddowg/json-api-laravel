<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedTargetServability;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the related-endpoint-target servability fixture: the {@see ShelfResource} alone,
 * over an empty in-memory read provider, so every other guard passes and only the
 * unregistered related target surfaces.
 */
final class RelatedTargetServabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ShelfResource::class]);

        JsonApi::provider(new InMemoryDataProvider('shelves', []));
    }
}
