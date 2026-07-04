<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\Genre;

/**
 * The Eloquent workbench wiring: it points discovery at the SAME `app/JsonApi`
 * resources the in-memory {@see WorkbenchServiceProvider} uses, and registers the
 * reference {@see EloquentDataProvider} at the lowest priority (`-128`, PLAN decision 2)
 * with a `type → model` map.
 *
 * Because this provider is registered WITHOUT the in-memory one (a conformance concrete
 * or the Eloquent feature test installs exactly one wiring), the Eloquent provider is
 * the only registration, so `-128` still wins the registry's first-`supports()` match —
 * yet the priority is faithful, so an application provider at the default `0` would
 * shadow it. The resources are shared: their `storedAs()` columns resolve off both the
 * POPOs and these models, which is what lets one workbench domain serve either suite.
 *
 * Migrations + seeding are the app/test harness's job (Testbench `defineDatabaseMigrations`,
 * or `testbench serve` with a migrate+seed step); this provider only wires the resources
 * and the provider.
 */
final class EloquentWorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/JsonApi']);

        JsonApi::provider(
            new EloquentDataProvider([
                'artists' => Artist::class,
                'albums' => Album::class,
                'genres' => Genre::class,
            ]),
            priority: -128,
        );
    }
}
