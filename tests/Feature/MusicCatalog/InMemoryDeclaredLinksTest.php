<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Domain\Track;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;

/**
 * The in-memory arm of the declared-links suite, over the seeded object graph.
 *
 * @internal
 */
#[CoversNothing]
final class InMemoryDeclaredLinksTest extends DeclaredLinksTestCase
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

    protected function clearDuration(string $id): void
    {
        $track = $this->app?->make(DataProviderRegistry::class)->forType('tracks')->fetchOne('tracks', $id);
        self::assertInstanceOf(Track::class, $track);
        $track->length_seconds = 0;
    }
}
