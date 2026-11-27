<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Workbench\App\Domain\CursorBoard;

/**
 * The in-memory `cursorBoards` provider for the cursor conformance suites, with each board's
 * `widgets` pivot rows written from its `positions`, as the Eloquent half's join table holds
 * them, so the suites' `meta.pivot` assertions are identical on both providers.
 */
final class CursorBoards
{
    /**
     * @param array<int|string, CursorBoard> $boards
     *
     * @return InMemoryDataProvider<CursorBoard>
     */
    public static function provider(array $boards): InMemoryDataProvider
    {
        $provider = new InMemoryDataProvider('cursorBoards', $boards);
        foreach ($boards as $board) {
            $rows = [];
            foreach ($board->positions as $widget => $position) {
                $rows[(string) $widget] = ['position' => $position];
            }
            $provider->store()->writePivotRows($board, 'widgets', $rows);
        }

        return $provider;
    }
}
