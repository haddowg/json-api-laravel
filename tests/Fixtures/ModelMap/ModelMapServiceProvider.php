<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ModelMap;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * The explicit-tier wiring for the model-mapping-tiers fixture app (ADR 0019): it
 * registers ONLY the in-memory pair for `imports` at the default priority `0` — every
 * other fixture type (`recordings`, `pressings`, `ghosts`) is left to the attribute /
 * convention / no tier, so the feature test observes each tier in isolation. Discovery
 * paths and the convention namespace are configured by the test's environment.
 *
 * @internal
 */
final class ModelMapServiceProvider extends ServiceProvider
{
    public const string SHADOW_TITLE = 'Served by the explicit in-memory registration';

    public function register(): void
    {
        $identify = static fn(object $item): string => $item instanceof ImportEntry ? $item->id : '';

        $provider = new InMemoryDataProvider(
            'imports',
            ['1' => new ImportEntry('1', self::SHADOW_TITLE)],
            identify: $identify,
        );
        JsonApi::provider($provider);
        JsonApi::persister(new InMemoryDataPersister('imports', $provider->store(), static fn(): ImportEntry => new ImportEntry()));
    }
}
