<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\CursorBoard;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the shared {@see ConformanceFixtures::cursorBoards()} partition into the
 * `cursor_boards` table and its `cursor_board_widget` pivot rows (member + `position`)
 * — the SAME membership and pivot values the in-memory
 * {@see \Workbench\App\Providers\PivotCursorConformanceInMemoryServiceProvider}
 * carries, so the Eloquent half of the pivot-cursor conformance suite reads identical
 * parent-scoped data AND identical `meta.pivot`. Runs AFTER {@see CursorWidgetSeeder}.
 */
final class CursorBoardSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConformanceFixtures::cursorBoards() as $row) {
            CursorBoard::query()->create(['id' => $row['id'], 'name' => $row['name']]);
            foreach ($row['widgets'] as $widgetId => $position) {
                DB::table('cursor_board_widget')->insert([
                    'board_id' => $row['id'],
                    'widget_id' => $widgetId,
                    'position' => $position,
                ]);
            }
        }
    }
}
