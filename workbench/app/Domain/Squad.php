<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A squad on the in-memory witness, mirroring the Eloquent {@see \Workbench\App\Models\Squad}.
 */
final class Squad
{
    /**
     * @param list<Player> $players
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $players = [],
    ) {}
}
