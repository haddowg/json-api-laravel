<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Artist;
use Workbench\App\Domain\Genre;

/**
 * The Testbench workbench's service provider: it points discovery at the workbench's
 * `app/JsonApi` directory and registers one seeded in-memory data provider per resource
 * type. The in-memory providers carry their fixture data (which the container cannot
 * supply), so they are registered explicitly via `JsonApi::provider()` rather than
 * discovered — the discovery scan finds only the resource classes.
 *
 * Everything runs in `register()` so it lands before the package provider's `boot()`
 * reads the discovery + provider registrations.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/JsonApi']);

        JsonApi::provider(new InMemoryDataProvider('artists', $this->artists()));
        JsonApi::provider(new InMemoryDataProvider('albums', $this->albums()));
        JsonApi::provider(new InMemoryDataProvider('genres', $this->genres()));
    }

    /**
     * @return array<int|string, Artist>
     */
    private function artists(): array
    {
        return [
            '1' => new Artist(
                id: '1',
                name: 'Radiohead',
                slug: 'radiohead',
                website: 'https://radiohead.com',
                bio: 'An English rock band formed in Abingdon.',
                trackCount: 3,
                createdAt: new \DateTimeImmutable('1985-01-01T00:00:00+00:00'),
            ),
            '2' => new Artist(
                id: '2',
                name: 'Portishead',
                slug: 'portishead',
                website: null,
                bio: null,
                trackCount: 2,
                createdAt: new \DateTimeImmutable('1991-01-01T00:00:00+00:00'),
            ),
        ];
    }

    /**
     * @return array<int|string, Album>
     */
    private function albums(): array
    {
        return [
            '1' => new Album(
                id: '1',
                title: 'OK Computer',
                averageRating: 9.8,
                status: 'released',
                explicit: false,
                availableFrom: new \DateTimeImmutable('1997-05-21T00:00:00+00:00'),
                releasedAt: new \DateTimeImmutable('1997-05-21T00:00:00+00:00'),
            ),
            '2' => new Album(
                id: '2',
                title: 'Dummy',
                averageRating: 9.1,
                status: 'released',
                explicit: false,
                availableFrom: new \DateTimeImmutable('1994-08-22T00:00:00+00:00'),
                releasedAt: new \DateTimeImmutable('1994-08-22T00:00:00+00:00'),
            ),
        ];
    }

    /**
     * @return array<int|string, Genre>
     */
    private function genres(): array
    {
        return [
            'trip-hop' => new Genre(id: 'trip-hop', name: 'Trip Hop'),
            'alt-rock' => new Genre(id: 'alt-rock', name: 'Alternative Rock'),
        ];
    }
}
