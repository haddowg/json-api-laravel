<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Models\Genre;
use Workbench\App\Support\ConformanceFixtures;

/**
 * Seeds the richer {@see ConformanceFixtures} rows into the Eloquent tables — the
 * SAME rows the in-memory {@see \Workbench\App\Providers\ConformanceInMemoryServiceProvider}
 * carries, so the Eloquent half of the read-conformance suite reads identical data.
 *
 * Distinct from the Phase-0 {@see MusicCatalogSeeder} (the minimal 2-row set the
 * feature suite asserts against), so the conformance dataset never perturbs the
 * existing tests.
 */
final class ConformanceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ConformanceFixtures::artists() as $row) {
            Artist::query()->create($row);
        }

        foreach (ConformanceFixtures::albums() as $row) {
            Album::query()->create($row);
        }

        foreach (ConformanceFixtures::genres() as $row) {
            Genre::query()->create($row);
        }
    }
}
