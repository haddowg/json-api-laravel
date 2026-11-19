<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\Fixtures;
use Workbench\Database\Seeders\McCatalogSeeder;

/**
 * The Eloquent arm of the music-catalog smoke suite: the full domain served over the
 * reference {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} against
 * a migrated + seeded in-memory SQLite database.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentMusicCatalogSmokeTest extends MusicCatalogSmokeTestCase
{
    use PinsCatalogClock;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogEloquentServiceProvider::class,
        ];
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

    public function test_a_filter_through_the_pivot_row_reads_its_pivot_field(): void
    {
        // Track 1 was added on 1 January, track 2 on 2 January.
        $playlist = '/api/playlists/' . Fixtures::PLAYLIST_ONE;

        $related = $this->fetch($playlist . '/orderedTracks?filter[addedAt][min]=2024-01-01T12:00:00Z')->assertOk();
        self::assertSame(['2'], \array_column((array) $related->json('data'), 'id'));

        $relationship = $this->fetch($playlist . '/relationships/orderedTracks?filter[addedAt][max]=2024-01-01T12:00:00Z')->assertOk();
        self::assertSame(['1'], \array_column((array) $relationship->json('data'), 'id'));

        $included = $this->get(
            $playlist . '?include=orderedTracks&relatedQuery[orderedTracks][filter][addedAt][min]=2024-01-01T12:00:00Z',
            ['Accept' => self::MEDIA_TYPE . ';profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"'],
        )->assertOk();
        self::assertSame(['2'], \array_column((array) $included->json('data.relationships.orderedTracks.data'), 'id'));
    }
}
