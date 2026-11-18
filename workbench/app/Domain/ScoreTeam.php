<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

use Workbench\App\KeysetSorts\ScoredTeam;

/**
 * A team seeded into the in-memory provider for the cursor-sort cases, the twin of the
 * Eloquent {@see \Workbench\App\Models\ScoreTeam}.
 */
final class ScoreTeam implements ScoredTeam
{
    /**
     * @param list<int> $scores
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?int $base = null,
        public ?int $bonus = null,
        public array $scores = [],
    ) {}

    public function base(): ?int
    {
        return $this->base;
    }

    public function bonus(): ?int
    {
        return $this->bonus;
    }

    public function scores(): array
    {
        return $this->scores;
    }
}
