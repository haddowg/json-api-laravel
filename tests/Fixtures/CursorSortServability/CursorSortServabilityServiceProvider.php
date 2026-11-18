<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\CursorSortServability;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\KeysetSorts\ScoreTeamResource;
use Workbench\App\Models\ScoreTeam;

/**
 * Wires two cursor-paged collections whose sorts can't key a page: shelves declaring a
 * sort without a keyset value, and teams whose Eloquent arm supplies no keyset expression.
 */
final class CursorSortServabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([UnkeyableShelfResource::class, ScoreTeamResource::class]);

        JsonApi::provider(new InMemoryDataProvider('unkeyableShelves', []));
        JsonApi::provider(new EloquentDataProvider(['scoreTeams' => ScoreTeam::class], sortArms: [new OrderOnlyTeamArm()]), priority: -128);
    }
}
