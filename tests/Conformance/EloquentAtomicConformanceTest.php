<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;
use Workbench\App\Providers\SurfaceEloquentServiceProvider;

/**
 * {@see AtomicConformanceTestCase} against the **reference Eloquent provider/persister pair**
 * over an in-memory SQLite database. The batch opens one outer transaction on the shared
 * persister; each sub-op's own write nests as a savepoint (ADR 0009 addendum), so the
 * all-or-nothing commit/rollback holds on real SQL exactly as the in-memory witness refs it.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentAtomicConformanceTest extends AtomicConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SurfaceEloquentServiceProvider::class;
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
        // A baseline matching the in-memory surface seed (artists 1/2, album 1) so the atomic
        // assertions read like-linked data and the created ids fall out the same.
        Artist::query()->create(['name' => 'Radiohead', 'slug' => 'radiohead', 'track_count' => 0, 'created_at' => '1985-01-01T00:00:00+00:00']);
        Artist::query()->create(['name' => 'Portishead', 'slug' => 'portishead', 'track_count' => 0, 'created_at' => '1991-01-01T00:00:00+00:00']);
        Album::query()->create(['artist_id' => 1, 'title' => 'OK Computer', 'status' => 'draft', 'explicit' => false, 'released_at' => '1997-05-21T00:00:00+00:00']);
    }
}
