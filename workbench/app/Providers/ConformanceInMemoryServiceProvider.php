<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Artist;
use Workbench\App\Domain\Genre;
use Workbench\App\Support\ConformanceFixtures;

/**
 * The **in-memory** half of the read-conformance dual-provider wiring: it points
 * discovery at the SAME `app/JsonApi` resources the Eloquent wiring
 * ({@see EloquentWorkbenchServiceProvider}) serves, and registers one
 * {@see InMemoryDataProvider} per type seeded from the richer
 * {@see ConformanceFixtures} — the identical rows the {@see \Workbench\Database\Seeders\ConformanceSeeder}
 * loads into SQLite, so the two conformance suites assert against like data and a
 * divergence localizes to one provider's execution (blueprint §5.4).
 *
 * Distinct from the Phase-0 {@see WorkbenchServiceProvider} (which seeds the minimal
 * 2-row {@see \Workbench\App\Support\Fixtures} the feature suite asserts against) so
 * enriching the conformance dataset never perturbs the existing tests. Everything
 * runs in `register()` so it lands before the package provider's `boot()` reads the
 * discovery + provider registrations.
 */
final class ConformanceInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/JsonApi']);

        // Build the artist ↔ album object graph once so the `HasMany('albums')` and
        // `BelongsTo('artist')` relations resolve off the SAME instances both stores hold —
        // the in-memory analogue of the FK the {@see \Workbench\Database\Seeders\ConformanceSeeder}
        // loads, so the two conformance suites read like-linked data.
        [$artists, $albums] = $this->musicCatalog();

        JsonApi::provider(new InMemoryDataProvider('artists', $artists));
        JsonApi::provider(new InMemoryDataProvider('albums', $albums));
        JsonApi::provider(new InMemoryDataProvider('genres', $this->genres()));
    }

    /**
     * Builds the linked artist ↔ album object graph from the shared {@see ConformanceFixtures}
     * (0/1/many albums per artist), each album pointing at its owner and each artist
     * collecting its albums — the same instances both stores hold.
     *
     * @return array{0: array<int|string, Artist>, 1: array<int|string, Album>}
     */
    private function musicCatalog(): array
    {
        $artists = [];
        foreach (ConformanceFixtures::artists() as $row) {
            $artists[(string) $row['id']] = new Artist(
                id: (string) $row['id'],
                name: $row['name'],
                slug: $row['slug'],
                website: $row['website'],
                bio: $row['bio'],
                track_count: $row['track_count'],
                created_at: $row['created_at'],
            );
        }

        $albums = [];
        foreach (ConformanceFixtures::albums() as $row) {
            $artist = $row['artist_id'] !== null ? ($artists[(string) $row['artist_id']] ?? null) : null;

            $album = new Album(
                id: (string) $row['id'],
                title: $row['title'],
                average_rating: $row['average_rating'],
                status: $row['status'],
                explicit: $row['explicit'],
                available_from: $row['available_from'],
                released_at: $row['released_at'],
                artist: $artist,
            );

            $albums[(string) $row['id']] = $album;
            if ($artist !== null) {
                $artist->albums[] = $album;
            }
        }

        return [$artists, $albums];
    }

    /**
     * @return array<string, Genre>
     */
    private function genres(): array
    {
        $genres = [];
        foreach (ConformanceFixtures::genres() as $row) {
            $genres[$row['id']] = new Genre(id: $row['id'], name: $row['name']);
        }

        return $genres;
    }
}
