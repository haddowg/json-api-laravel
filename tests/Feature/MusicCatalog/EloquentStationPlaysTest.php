<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * {@see StationPlaysTestCase} with the plays on Eloquent, where the include reads every
 * station's plays in one query.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentStationPlaysTest extends StationPlaysTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, MusicCatalogEloquentServiceProvider::class];
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
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new McCatalogSeeder())->run();
    }

    #[Test]
    public function theIncludeReadsThePlaysInOneQuery(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->jsonApi()->get('/api/stations?include=plays')->assertOk();

        // The plays' own track linkage loads in a further query of its own.
        $plays = \array_filter(DB::getQueryLog(), static fn(array $entry): bool => \str_contains((string) $entry['query'], 'from "mc_plays"'));
        self::assertCount(1, $plays);
    }
}
