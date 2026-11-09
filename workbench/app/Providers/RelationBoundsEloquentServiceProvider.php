<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Player;
use Workbench\App\Models\Squad;
use Workbench\App\RelationBounds\PlayerResource;
use Workbench\App\RelationBounds\SquadResource;

/**
 * The Eloquent half of the relation-bounds conformance wiring.
 */
final class RelationBoundsEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([SquadResource::class, PlayerResource::class]);

        $modelByType = ['squads' => Squad::class, 'players' => Player::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
