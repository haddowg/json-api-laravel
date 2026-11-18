<?php

declare(strict_types=1);

namespace Workbench\App\KeysetSorts;

interface ScoredTeam
{
    public function base(): ?int;

    public function bonus(): ?int;

    /**
     * @return list<int>
     */
    public function scores(): array;
}
