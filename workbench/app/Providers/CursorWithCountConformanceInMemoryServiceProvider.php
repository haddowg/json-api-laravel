<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\CursorBoard;
use Workbench\App\Domain\CursorGroup;
use Workbench\App\Domain\CursorWidget;
use Workbench\App\Support\ConformanceFixtures;
use Workbench\App\Support\CursorBoardInMemoryDataProvider;

/**
 * The in-memory twin of {@see CursorWithCountConformanceEloquentServiceProvider}, seeded from
 * the shared fixtures.
 */
final class CursorWithCountConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor', \dirname(__DIR__) . '/CursorRelated', \dirname(__DIR__) . '/CursorPivot']);

        $widgets = [];
        foreach (ConformanceFixtures::cursorWidgets() as $row) {
            $widgets[(string) $row['id']] = new CursorWidget(
                id: (string) $row['id'],
                category: $row['category'],
                priority: $row['priority'],
                released_at: $row['released_at'],
            );
        }

        $groups = [];
        foreach (ConformanceFixtures::cursorGroups() as $row) {
            $groups[(string) $row['id']] = new CursorGroup(
                id: (string) $row['id'],
                name: $row['name'],
                widgets: \array_values(\array_map(static fn(int $id): CursorWidget => $widgets[(string) $id], $row['widget_ids'])),
            );
        }

        $boards = [];
        foreach (ConformanceFixtures::cursorBoards() as $row) {
            $boards[(string) $row['id']] = new CursorBoard(
                id: (string) $row['id'],
                name: $row['name'],
                widgets: \array_values(\array_map(static fn(int $id): CursorWidget => $widgets[(string) $id], \array_keys($row['widgets']))),
                positions: $row['widgets'],
            );
        }

        JsonApi::provider(new InMemoryDataProvider('cursorWidgets', $widgets));
        JsonApi::provider(new InMemoryDataProvider('cursorGroups', $groups));
        JsonApi::provider(new CursorBoardInMemoryDataProvider(new InMemoryDataProvider('cursorBoards', $boards)));
    }
}
