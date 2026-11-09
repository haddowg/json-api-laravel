<?php

declare(strict_types=1);

namespace Workbench\App\Domain;

/**
 * A player, the related side of the relation-bounds squads on the in-memory witness.
 */
final class Player
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
