<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\CursorWidget;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the shared {@see ConformanceFixtures::cursorWidgets()} rows into the
 * `cursor_widgets` table — the SAME rows the in-memory
 * {@see \Workbench\App\Providers\CursorConformanceInMemoryServiceProvider} carries, so
 * the Eloquent half of the cursor (keyset) conformance suite reads identical data.
 */
final class CursorWidgetSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConformanceFixtures::cursorWidgets() as $row) {
            CursorWidget::query()->create($row);
        }
    }
}
