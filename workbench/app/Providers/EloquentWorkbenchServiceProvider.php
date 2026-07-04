<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\Genre;

/**
 * The Eloquent workbench wiring: it points discovery at the SAME `app/JsonApi`
 * resources the in-memory {@see WorkbenchServiceProvider} uses, and registers the
 * reference {@see EloquentDataProvider} + {@see EloquentDataPersister} pair at the lowest
 * priority (`-128`, PLAN decision 2) over ONE `type → model` map.
 *
 * Because this provider is registered WITHOUT the in-memory one (a conformance concrete
 * or the Eloquent feature test installs exactly one wiring), the Eloquent pair is the
 * only registration, so `-128` still wins the registry's first-`supports()` match —
 * yet the priority is faithful, so an application provider/persister at the default `0`
 * would shadow it. The resources are shared: their `storedAs()` columns resolve off both
 * the POPOs and these models, which is what lets one workbench domain serve either suite;
 * the persister commits `POST`/`PATCH`/`DELETE` for the writable `albums`/`genres` types
 * (its `artists` support is never reached — that type stays read-only).
 *
 * Migrations + seeding are the app/test harness's job (Testbench `defineDatabaseMigrations`,
 * or `testbench serve` with a migrate+seed step); this provider only wires the resources
 * and the provider/persister pair.
 */
final class EloquentWorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/JsonApi']);

        $modelByType = [
            'artists' => Artist::class,
            'albums' => Album::class,
            'genres' => Genre::class,
        ];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);

        // The storage-aware load-state predicate (PLAN decision 8): core consults it for a
        // lazy relation so a preloaded (setRelation) relation renders without a re-fetch and
        // an unloaded one renders links-only without a query. The package's ServerFactory
        // wires whatever is bound to RelationshipLoadStateInterface into every Server.
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }
}
