<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PolymorphicServability;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the polymorphic servability failure fixture: the {@see CatalogueResource} host and its
 * two candidate types (one discriminating, one flawed), each over an empty in-memory read
 * provider so every type is otherwise servable and only the discrimination problem surfaces.
 */
final class PolymorphicServabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CatalogueResource::class, GoodMemberResource::class, FlawedMemberResource::class]);

        JsonApi::provider(new InMemoryDataProvider('catalogues', []));
        JsonApi::provider(new InMemoryDataProvider('good_members', []));
        JsonApi::provider(new InMemoryDataProvider('flawed_members', []));
    }
}
