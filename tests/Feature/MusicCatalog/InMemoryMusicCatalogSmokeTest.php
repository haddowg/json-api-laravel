<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * The in-memory arm of the music-catalog smoke suite: the SAME full domain served over
 * seeded {@see \haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider}s carrying the
 * fixtures as a POPO object graph — the dual-provider witness against the Eloquent arm.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryMusicCatalogSmokeTest extends MusicCatalogSmokeTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogInMemoryServiceProvider::class,
        ];
    }
}
