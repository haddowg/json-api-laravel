<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

/**
 * The boards, notes and photos behind the link row cases, shared by both providers. Board 1
 * holds two notes and two photos whose positions and labels put them in a different order
 * under each sort.
 */
final class Fixtures
{
    /**
     * @return array<int, string>
     */
    public static function notes(): array
    {
        return [1 => 'Groceries', 2 => 'Ideas', 3 => 'Todo'];
    }

    /**
     * @return array<int, string>
     */
    public static function photos(): array
    {
        return [1 => 'Beach', 2 => 'Hills'];
    }

    /**
     * Each board's link rows in storage order, and its pinned notes with their ranks.
     *
     * @return array<int, array{name: string, items: list<array{type: string, id: int, position: int, label: ?string}>, pins: array<int, int>}>
     */
    public static function boards(): array
    {
        return [
            1 => [
                'name' => 'Weekend',
                'items' => [
                    ['type' => 'notes', 'id' => 1, 'position' => 2, 'label' => 'home'],
                    ['type' => 'photos', 'id' => 1, 'position' => 0, 'label' => 'trip'],
                    ['type' => 'notes', 'id' => 2, 'position' => 3, 'label' => 'work'],
                    ['type' => 'photos', 'id' => 2, 'position' => 1, 'label' => 'trip'],
                ],
                'pins' => [3 => 1, 1 => 0],
            ],
            2 => ['name' => 'Empty', 'items' => [], 'pins' => []],
        ];
    }
}
