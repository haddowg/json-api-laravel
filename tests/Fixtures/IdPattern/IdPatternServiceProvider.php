<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdPattern;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the numeric-Id fixture: a read-only {@see NumericThingResource} over a seeded
 * in-memory provider, so the route registrar composes the declared Id pattern into the
 * `{id}` requirement.
 */
final class IdPatternServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([NumericThingResource::class]);
        JsonApi::provider(new InMemoryDataProvider('numeric_things', ['1' => new NumericThing('1', 'One')]));
    }
}
