<?php

declare(strict_types=1);

namespace Workbench\App\RelationOrder;

/**
 * The shelves and books behind the relation read order cases, shared by both providers.
 * Each book's title, year and genre put it in a different place under each order.
 */
final class Fixtures
{
    /**
     * @return array<int, array{title: string, year: int, genre: string, shelf: int}>
     */
    public static function books(): array
    {
        return [
            1 => ['title' => 'Dune', 'year' => 1965, 'genre' => 'sf', 'shelf' => 1],
            2 => ['title' => 'Anathem', 'year' => 2008, 'genre' => 'sf', 'shelf' => 1],
            3 => ['title' => 'Neuromancer', 'year' => 1984, 'genre' => 'cyberpunk', 'shelf' => 1],
            4 => ['title' => 'Contact', 'year' => 1985, 'genre' => 'sf', 'shelf' => 1],
            5 => ['title' => 'Blindsight', 'year' => 2006, 'genre' => 'sf', 'shelf' => 2],
        ];
    }

    /**
     * Each shelf's favourites, and its picks with their ranks, in storage order.
     *
     * @return array<int, array{name: string, favourites: list<int>, picks: array<int, int>}>
     */
    public static function shelves(): array
    {
        return [
            1 => ['name' => 'Classics', 'favourites' => [3, 1, 4], 'picks' => [1 => 1, 2 => 2, 4 => 0]],
            2 => ['name' => 'Recent', 'favourites' => [5, 2], 'picks' => [5 => 0]],
        ];
    }
}
