<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\Genre;
use Workbench\App\Support\Fixtures;

/**
 * Seeds the canonical music-catalog rows (the shared {@see Fixtures}) into the Eloquent
 * tables — the same rows the in-memory provider carries, so the Eloquent workbench
 * serves identical data under `testbench serve` and the feature suite.
 */
final class MusicCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Fixtures::artists() as $row) {
            Artist::query()->create($row);
        }

        foreach (Fixtures::albums() as $row) {
            Album::query()->create($row);
        }

        foreach (Fixtures::genres() as $row) {
            Genre::query()->create($row);
        }
    }
}
