<?php

declare(strict_types=1);

namespace Workbench\App\RelationBounds;

/**
 * The seed both relation-bounds wirings serve: three players, and squad 1 fielding
 * player 1.
 */
final class Fixtures
{
    /**
     * @return array<int, string>
     */
    public static function players(): array
    {
        return [1 => 'Ada', 2 => 'Grace', 3 => 'Linus'];
    }

    /**
     * @return array<int, array{name: string, players: list<int>}>
     */
    public static function squads(): array
    {
        return [1 => ['name' => 'Reds', 'players' => [1]]];
    }
}
