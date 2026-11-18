<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A league or division held in memory, with every team.
 */
final class ScoreGroup
{
    /**
     * @param list<ScoreTeam> $teams
     * @param list<ScoreTeam> $slottedTeams
     */
    public function __construct(
        public string $id,
        public array $teams,
        public array $slottedTeams,
    ) {}
}
