<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\CursorGroup;
use Workbench\App\Domain\CursorWidget;
use Workbench\App\Support\ConformanceFixtures;

/**
 * The **in-memory** half of the RELATED-collection cursor (keyset) conformance wiring:
 * it discovers the isolated cursor resources ({@see \Workbench\App\Cursor\CursorWidgetResource}
 * plus the {@see \Workbench\App\CursorRelated\CursorGroupResource} parent) and registers
 * one {@see InMemoryDataProvider} per type — the groups seeded from the shared
 * {@see ConformanceFixtures::cursorGroups()} partition holding the SAME widget POPO
 * instances the widgets store carries. `GET /cursorGroups/{id}/widgets` reads the
 * member set off the parent and runs the ground-truth keyset the Eloquent parent-scoped
 * push-down must match byte-for-byte (docs/adr/0016, bundle ADR 0063).
 */
final class RelatedCursorConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor', \dirname(__DIR__) . '/CursorRelated']);

        $widgets = $this->cursorWidgets();

        JsonApi::provider(new InMemoryDataProvider('cursorWidgets', $widgets));
        JsonApi::provider(new InMemoryDataProvider('cursorGroups', $this->cursorGroups($widgets)));
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
     * @return array<int|string, CursorGroup>
     */
    private function cursorGroups(array $widgets): array
    {
        $groups = [];
        foreach (ConformanceFixtures::cursorGroups() as $row) {
            $groups[(string) $row['id']] = new CursorGroup(
                id: (string) $row['id'],
                name: $row['name'],
                widgets: \array_values(\array_map(
                    static fn(int $id): CursorWidget => $widgets[(string) $id],
                    $row['widget_ids'],
                )),
            );
        }

        return $groups;
    }
}
