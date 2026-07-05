<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\CursorBoard;
use Workbench\App\Domain\CursorWidget;
use Workbench\App\Support\ConformanceFixtures;
use Workbench\App\Support\CursorBoardInMemoryDataProvider;

/**
 * The **in-memory** half of the PIVOT-bearing related-collection cursor (keyset)
 * conformance wiring: it discovers the isolated cursor resources
 * ({@see \Workbench\App\Cursor\CursorWidgetResource} plus the
 * {@see \Workbench\App\CursorPivot\CursorBoardResource} pivot parent) and registers
 * one provider per type — the boards store wrapped in the
 * {@see CursorBoardInMemoryDataProvider} so the pivot map (the one seam the built-in
 * witness leaves empty) serves the SAME `position` values the Eloquent join stores,
 * seeded from the shared {@see ConformanceFixtures::cursorBoards()} partition over the
 * SAME widget POPO instances the widgets store carries. `GET /cursorBoards/{id}/widgets`
 * then reads the member set off the parent and runs the ground-truth keyset the
 * Eloquent pivot-joined push-down must match byte-for-byte, `meta.pivot` included
 * (docs/adr/0017).
 */
final class PivotCursorConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor', \dirname(__DIR__) . '/CursorPivot']);

        $widgets = $this->cursorWidgets();

        JsonApi::provider(new InMemoryDataProvider('cursorWidgets', $widgets));
        JsonApi::provider(new CursorBoardInMemoryDataProvider(
            new InMemoryDataProvider('cursorBoards', $this->cursorBoards($widgets)),
        ));
    }

    /**
     * @return array<int|string, CursorWidget>
     */
    private function cursorWidgets(): array
    {
        $widgets = [];
        foreach (ConformanceFixtures::cursorWidgets() as $row) {
            $widgets[(string) $row['id']] = new CursorWidget(
                id: (string) $row['id'],
                category: $row['category'],
                priority: $row['priority'],
                released_at: $row['released_at'],
            );
        }

        return $widgets;
    }

    /**
     * @param array<int|string, CursorWidget> $widgets
     *
     * @return array<int|string, CursorBoard>
     */
    private function cursorBoards(array $widgets): array
    {
        $boards = [];
        foreach (ConformanceFixtures::cursorBoards() as $row) {
            $boards[(string) $row['id']] = new CursorBoard(
                id: (string) $row['id'],
                name: $row['name'],
                widgets: \array_values(\array_map(
                    static fn(int $id): CursorWidget => $widgets[(string) $id],
                    \array_keys($row['widgets']),
                )),
                positions: $row['widgets'],
            );
        }

        return $boards;
    }
}
