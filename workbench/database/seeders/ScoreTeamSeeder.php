<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\ScoreDivision;
use Workbench\App\Models\ScoreLeague;
use Workbench\App\Models\ScoreMember;
use Workbench\App\Models\ScoreTeam;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the shared {@see ConformanceFixtures::scoreTeams()} rows, their members' scores,
 * division 1 every team belongs to, and league 1 holding every team.
 */
final class ScoreTeamSeeder extends Seeder
{
    public function run(): void
    {
        $league = ScoreLeague::query()->create(['id' => 1]);
        ScoreDivision::query()->create(['id' => 1]);

        foreach (ConformanceFixtures::scoreTeams() as $row) {
            ScoreTeam::query()->create(['id' => $row['id'], 'name' => $row['name'], 'base' => $row['base'], 'bonus' => $row['bonus'], 'score_division_id' => 1]);
            $league->teams()->attach($row['id'], ['slot' => $row['id']]);

            foreach ($row['scores'] as $score) {
                ScoreMember::query()->create(['score_team_id' => $row['id'], 'score' => $score]);
            }
        }
    }
}
