<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Domain\Album;
use Workbench\App\Domain\Artist;
use Workbench\App\Domain\Genre;
use Workbench\App\Support\Fixtures;

/**
 * The in-memory workbench wiring: it points discovery at `app/JsonApi` and registers
 * one seeded {@see InMemoryDataProvider} per resource type at the default priority. The
 * in-memory providers carry their fixture data (which the container cannot supply), so
 * they are registered explicitly via `JsonApi::provider()` rather than discovered.
 *
 * `albums` and `genres` are **writable**: each is constructed with an id accessor pair,
 * and its {@see InMemoryDataProvider::store()} is shared into an
 * {@see InMemoryDataPersister} registered via `JsonApi::persister()`, so a write is
 * immediately visible to a subsequent read (the in-memory analogue of a shared
 * connection). `albums` uses **store-provided** ids (the `assignId` closure mints the next
 * sequential id past the seed, mirroring an auto-increment); `genres` needs no `assignId`
 * because its id is client-generated (a natural key). `artists` stays read-only (no
 * persister), so its write routes are gated out.
 *
 * The seed rows come from the shared {@see Fixtures} the Eloquent wiring
 * ({@see EloquentWorkbenchServiceProvider}) also seeds, so the two provider suites read
 * identical data — the dual-provider conformance premise.
 *
 * Everything runs in `register()` so it lands before the package provider's `boot()`
 * reads the discovery + provider registrations.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([\dirname(__DIR__) . '/JsonApi']);

        // Build the artist ↔ album object graph once so the `HasMany('albums')` and
        // `BelongsTo('artist')` relations resolve off the SAME instances both stores hold
        // (a cross-store shared reference — the in-memory analogue of the Eloquent FK).
        [$artists, $albums] = $this->musicCatalog();

        JsonApi::provider(new InMemoryDataProvider('artists', $artists));

        $albumProvider = new InMemoryDataProvider('albums', $albums, identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($albumProvider);
        JsonApi::persister(new InMemoryDataPersister('albums', $albumProvider->store(), static fn(): Album => new Album()));

        $genres = new InMemoryDataProvider('genres', $this->genres(), identify: self::identify());
        JsonApi::provider($genres);
        JsonApi::persister(new InMemoryDataPersister('genres', $genres->store(), static fn(): Genre => new Genre()));
    }

    /**
     * Reads an item's JSON:API id off its `id` member through the framework-neutral
     * {@see Accessor} (the same access the resource fields use), typed over `object` so it
     * satisfies the store's `Closure(object): string` contract for either POPO.
     */
    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    /**
     * Writes a store-minted id back onto an item's `id` member — enabling store-provided
     * (auto-increment) ids on an id-less create.
     */
    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }

    /**
     * Builds the linked artist ↔ album object graph from the shared {@see Fixtures}: each
     * {@see Album}'s `$artist` points at its owner {@see Artist} instance, and each artist's
     * `$albums` collects the albums that point back — the same instances both stores hold,
     * so the two relations resolve off one graph.
     *
     * @return array{0: array<int|string, Artist>, 1: array<int|string, Album>}
     */
    private function musicCatalog(): array
    {
        $artists = [];
        foreach (Fixtures::artists() as $row) {
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
        foreach (Fixtures::albums() as $row) {
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
        foreach (Fixtures::genres() as $row) {
            $genres[$row['id']] = new Genre(id: $row['id'], name: $row['name']);
        }

        return $genres;
    }
}
