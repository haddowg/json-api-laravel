<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\ScoreGroup;
use Workbench\App\Domain\ScoreTeam;
use Workbench\App\KeysetSorts\TeamArrayArm;
use Workbench\App\Support\ConformanceFixtures;

/**
 * The in-memory half of the cursor-sort conformance wiring, seeded from
 * {@see ConformanceFixtures::scoreTeams()}: the witness, ordering by each sort's
 * `keysetValue()`.
 */
final class KeysetSortConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $teams = [];
        foreach (ConformanceFixtures::scoreTeams() as $row) {
            $teams[(string) $row['id']] = new ScoreTeam((string) $row['id'], $row['name'], $row['base'], $row['bonus'], $row['scores']);
        }

        JsonApi::discover([\dirname(__DIR__) . '/KeysetSorts']);

        $members = \array_values($teams);
        JsonApi::provider(new InMemoryDataProvider('scoreTeams', $teams, sortArms: [new TeamArrayArm()]));
        // Each team's `slot` pivot row, its id, as the Eloquent join holds it.
        $slots = [];
        foreach ($teams as $id => $team) {
            $slots[$id] = ['slot' => (int) $id];
        }
        foreach (['scoreLeagues', 'scoreDivisions'] as $type) {
            $group = new ScoreGroup('1', $members, $members);
            $provider = new InMemoryDataProvider($type, ['1' => $group], sortArms: [new TeamArrayArm()]);
            $provider->store()->writePivotRows($group, 'slottedTeams', $slots);
            JsonApi::provider($provider);
        }
    }
}
