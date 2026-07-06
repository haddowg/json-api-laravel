<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Providers\AsyncEloquentServiceProvider;

/**
 * {@see AsyncWriteConformanceTestCase} against the **reference Eloquent provider** over an
 * in-memory SQLite database, with the async `albums` persister shadowing the reference
 * persister at the default priority. The async accept never commits, so the seeded rows are
 * unchanged after a `202` exactly as on the in-memory witness — the seam is provider-agnostic.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentAsyncWriteConformanceTest extends AsyncWriteConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return AsyncEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        // A baseline matching the in-memory async seed (artists 1/2, album 1).
        Artist::query()->create(['name' => 'Radiohead', 'slug' => 'radiohead', 'track_count' => 0, 'created_at' => '1985-01-01T00:00:00+00:00']);
        Artist::query()->create(['name' => 'Portishead', 'slug' => 'portishead', 'track_count' => 0, 'created_at' => '1991-01-01T00:00:00+00:00']);
        Album::query()->create(['artist_id' => 1, 'title' => 'OK Computer', 'status' => 'draft', 'explicit' => false, 'released_at' => '1997-05-21T00:00:00+00:00']);
    }
}
