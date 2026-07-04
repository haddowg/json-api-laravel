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
use Workbench\App\Domain\Playlist;
use Workbench\App\Domain\Track;
use Workbench\App\Pivot\PlaylistResource;
use Workbench\App\Pivot\TrackResource;
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

        // The Phase-3b pivot resources live OUTSIDE the scanned `app/JsonApi` dir (so the
        // shared feature/scanner fixtures stay a stable inventory) and are registered
        // explicitly on the conformance wirings that serve them.
        JsonApi::register([PlaylistResource::class, TrackResource::class]);

        // Build the artist ↔ album object graph once so the `HasMany('albums')` and
        // `BelongsTo('artist')` relations resolve off the SAME instances both stores hold —
        // the in-memory analogue of the FK the {@see \Workbench\Database\Seeders\ConformanceSeeder}
        // loads, so the two conformance suites read like-linked data.
        [$artists, $albums] = $this->musicCatalog();

        JsonApi::provider(new InMemoryDataProvider('artists', $artists));
        JsonApi::provider(new InMemoryDataProvider('genres', $this->genres()));

        // `albums` is writable on the conformance wiring (Phase 3b relationship-write
        // conformance): its store is shared into a persister whose relatedResolver turns an
        // incoming `artists` linkage id back into the stored Artist, so a whole-resource
        // create/update embedding `data.relationships.artist` and a
        // `…/albums/{id}/relationships/artist` mutation are writable on the witness too — the
        // in-memory analogue of the Eloquent persister's `modelByType` lookup. Store-provided
        // ids (assignId) mirror the SQLite auto-increment for a parity create id.
        $albumProvider = new InMemoryDataProvider('albums', $albums, identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($albumProvider);
        $albumResolver = static fn(string $type, string $id): ?object => $type === 'artists' ? ($artists[$id] ?? null) : null;
        JsonApi::persister(new InMemoryDataPersister('albums', $albumProvider->store(), static fn(): Album => new Album(), $albumResolver));

        // The Phase-3b pivot surface: playlists ⇄ tracks. The in-memory witness stores no
        // pivot meta (the documented boundary — `meta.pivot` is Eloquent-only), so it wires
        // the plain member lists the `orderedTracks`/`tracks` relations resolve off, and a
        // writable `playlists` persister whose relatedResolver turns an incoming `tracks`
        // linkage id back into the stored Track — making the relationship-mutation endpoints
        // writable on the witness too (the pivot meta is simply not stored/rendered).
        [$playlists, $tracks] = $this->pivotCatalog();

        JsonApi::provider(new InMemoryDataProvider('tracks', $tracks, identify: self::identify()));

        $playlistProvider = new InMemoryDataProvider('playlists', $playlists, identify: self::identify());
        JsonApi::provider($playlistProvider);

        $relatedResolver = static fn(string $type, string $id): ?object => match ($type) {
            'tracks' => $tracks[$id] ?? null,
            'playlists' => $playlists[$id] ?? null,
            default => null,
        };
        JsonApi::persister(new InMemoryDataPersister('playlists', $playlistProvider->store(), static fn(): Playlist => new Playlist(), $relatedResolver));
    }

    /**
     * Builds the linked playlist ⇄ track object graph from the shared {@see ConformanceFixtures}
     * (0/1/many members per playlist, a track shared across playlists), each playlist
     * collecting its members and each track its playlists — the same instances both stores
     * hold, so a related/relationship read and the existence filters resolve off one graph.
     *
     * @return array{0: array<int|string, Playlist>, 1: array<int|string, Track>}
     */
    private function pivotCatalog(): array
    {
        $tracks = [];
        foreach (ConformanceFixtures::tracks() as $row) {
            $tracks[(string) $row['id']] = new Track(
                id: (string) $row['id'],
                title: $row['title'],
                released_at: $row['released_at'],
            );
        }

        $playlists = [];
        foreach (ConformanceFixtures::playlists() as $row) {
            $playlists[(string) $row['id']] = new Playlist(
                id: (string) $row['id'],
                title: $row['title'],
                public: $row['public'],
            );
        }

        foreach (ConformanceFixtures::playlistTracks() as $row) {
            $playlist = $playlists[(string) $row['playlist_id']] ?? null;
            $track = $tracks[(string) $row['track_id']] ?? null;
            if ($playlist === null || $track === null) {
                continue;
            }
            $playlist->orderedTracks[] = $track;
            $playlist->tracks[] = $track;
            $track->playlists[] = $playlist;
        }

        return [$playlists, $tracks];
    }

    /**
     * Reads an item's JSON:API id off its `id` member through the framework-neutral
     * {@see Accessor} — the store's `Closure(object): string` id contract for the writable
     * playlists store.
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
     * (auto-increment) ids on an id-less create, mirroring the Eloquent auto-increment.
     */
    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
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
