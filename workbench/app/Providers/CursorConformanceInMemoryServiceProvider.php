<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\CursorWidget;
use Workbench\App\Support\ConformanceFixtures;

/**
 * The **in-memory** half of the cursor (keyset) conformance wiring: it discovers the
 * isolated {@see \Workbench\App\Cursor\CursorWidgetResource} (NOT the artists/albums/
 * genres `app/JsonApi` dir) and registers one {@see InMemoryDataProvider} seeded from
 * the shared {@see ConformanceFixtures::cursorWidgets()} — the ground-truth witness the
 * Eloquent keyset push-down must match byte-for-byte (bundle ADR 0063).
 */
final class CursorConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/Cursor']);

        JsonApi::provider(new InMemoryDataProvider('cursorWidgets', $this->cursorWidgets()));
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
}
