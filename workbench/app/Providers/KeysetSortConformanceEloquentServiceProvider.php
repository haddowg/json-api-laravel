<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\KeysetSorts\TeamEloquentArm;
use Workbench\App\Models\ScoreLeague;
use Workbench\App\Models\ScoreTeam;

/**
 * The Eloquent half of the cursor-sort conformance wiring: teams over the reference
 * provider with the team sort arm, and a league holding them through a pivot table.
 */
final class KeysetSortConformanceEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/KeysetSorts', \dirname(__DIR__) . '/KeysetSortLeagues']);

        JsonApi::provider(
            new EloquentDataProvider(['scoreTeams' => ScoreTeam::class, 'scoreLeagues' => ScoreLeague::class], sortArms: [new TeamEloquentArm()]),
            priority: -128,
        );
    }
}
