<?php

declare(strict_types=1);

namespace Workbench\App\Positioned;

/**
 * The queues and songs behind the positioned relation cases, shared by both providers. Queue 1
 * holds songs 1 to 3 at slots 0 to 2 on each of its relations; song 4 is free and song 5 sits
 * on queue 2.
 */
final class Fixtures
{
    /**
     * @return array<int, array{title: string, queue: ?int, slot: ?int}>
     */
    public static function songs(): array
    {
        return [
            1 => ['title' => 'Alpha', 'queue' => 1, 'slot' => 0],
            2 => ['title' => 'Bravo', 'queue' => 1, 'slot' => 1],
            3 => ['title' => 'Charlie', 'queue' => 1, 'slot' => 2],
            4 => ['title' => 'Delta', 'queue' => null, 'slot' => null],
            5 => ['title' => 'Echo', 'queue' => 2, 'slot' => 0],
        ];
    }

    /**
     * Each queue's favourites and mix, song ids in position order from 0.
     *
     * @return array<int, array{name: string, favourites: list<int>, mix: list<int>}>
     */
    public static function queues(): array
    {
        return [
            1 => ['name' => 'Morning', 'favourites' => [1, 2, 3], 'mix' => [1, 2, 3]],
            2 => ['name' => 'Evening', 'favourites' => [], 'mix' => []],
        ];
    }
}
