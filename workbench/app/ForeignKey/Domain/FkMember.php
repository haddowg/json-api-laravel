<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Domain;

/**
 * The in-memory twin of {@see \Workbench\App\ForeignKey\Models\FkMember}.
 */
final class FkMember
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?FkTeam $team = null,
    ) {}
}
