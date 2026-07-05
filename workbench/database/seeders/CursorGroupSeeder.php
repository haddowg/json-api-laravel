<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\CursorGroup;
use Workbench\App\Models\CursorWidget;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the shared {@see ConformanceFixtures::cursorGroups()} partition into the
 * `cursor_groups` table and stamps each member widget's `group_id` — the SAME
 * membership the in-memory
 * {@see \Workbench\App\Providers\RelatedCursorConformanceInMemoryServiceProvider}
 * carries, so the Eloquent half of the related-collection cursor (keyset) conformance
 * suite reads identical parent-scoped data. Runs AFTER {@see CursorWidgetSeeder}.
 */
final class CursorGroupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConformanceFixtures::cursorGroups() as $row) {
            CursorGroup::query()->create(['id' => $row['id'], 'name' => $row['name']]);
            CursorWidget::query()->whereIn('id', $row['widget_ids'])->update(['group_id' => $row['id']]);
        }
    }
}
