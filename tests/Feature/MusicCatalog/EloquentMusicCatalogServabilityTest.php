<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;

/**
 * The servability warmer's success path over the FULL music-catalog Eloquent surface
 * (PLAN decision 11): every type of the twelve-type domain — including the relations whose
 * read path bypasses the Eloquent relation method, `libraries.items` (`extractUsing` over
 * three `morphedByMany` arms) and `playlists.publicOwner` (`storedAs('owner')` onto the
 * model's `owner()` method) — must warm CLEAN. A warmer that demands a model method named
 * after the JSON:API relation would false-flag both and fail a perfectly servable deploy.
 *
 * @internal
 */
final class EloquentMusicCatalogServabilityTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

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
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.servers', [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ]);
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

    #[Test]
    #[Group('openapi')]
    public function the_full_eloquent_music_catalog_surface_warms_clean(): void
    {
        $problems = $this->resolve(ServableResourceWarmer::class)->warm();

        $this->assertSame([], $problems);
    }

    #[Test]
    #[Group('openapi')]
    public function optimize_succeeds_over_the_full_eloquent_surface(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
    }
}
