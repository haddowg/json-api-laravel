<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User as AuthUser;
use Workbench\App\MusicCatalog\Action\ReissueAlbum;
use Workbench\App\MusicCatalog\Action\SummarizeAlbums;
use Workbench\App\MusicCatalog\Action\UploadAlbumArtwork;
use Workbench\App\MusicCatalog\Domain\Album;
use Workbench\App\MusicCatalog\Domain\Artist;
use Workbench\App\MusicCatalog\Domain\Device;
use Workbench\App\MusicCatalog\Domain\Favorite;
use Workbench\App\MusicCatalog\Domain\Genre;
use Workbench\App\MusicCatalog\Domain\Library;
use Workbench\App\MusicCatalog\Domain\Playlist;
use Workbench\App\MusicCatalog\Domain\PlaylistEntry;
use Workbench\App\MusicCatalog\Domain\Product;
use Workbench\App\MusicCatalog\Domain\Track;
use Workbench\App\MusicCatalog\Domain\User;
use Workbench\App\MusicCatalog\JsonApi\AlbumResource;
use Workbench\App\MusicCatalog\JsonApi\ArtistResource;
use Workbench\App\MusicCatalog\JsonApi\DeviceResource;
use Workbench\App\MusicCatalog\JsonApi\FavoriteResource;
use Workbench\App\MusicCatalog\JsonApi\GenreResource;
use Workbench\App\MusicCatalog\JsonApi\LibraryResource;
use Workbench\App\MusicCatalog\JsonApi\PlaylistResource;
use Workbench\App\MusicCatalog\JsonApi\ProductResource;
use Workbench\App\MusicCatalog\JsonApi\PublicProfileResource;
use Workbench\App\MusicCatalog\JsonApi\TrackResource;
use Workbench\App\MusicCatalog\JsonApi\UserResource;
use Workbench\App\MusicCatalog\Provider\ChartProvider;
use Workbench\App\MusicCatalog\Provider\CountryProvider;
use Workbench\App\MusicCatalog\Query\ArrayFullTextSearchArm;
use Workbench\App\MusicCatalog\Security\PlaylistApiPolicy;
use Workbench\App\MusicCatalog\Serializer\ChartSerializer;
use Workbench\App\MusicCatalog\Serializer\CountrySerializer;
use Workbench\App\MusicCatalog\Support\Fixtures;

/**
 * The in-memory half of the unified music-catalog wiring (decision 14): it registers the
 * SAME full-domain resources the Eloquent half serves, but over seeded
 * {@see InMemoryDataProvider}s carrying the {@see Fixtures} as a linked POPO object graph —
 * so the dual-provider feature suite reads identical data through both arms. The polymorphic
 * `favorites.favoritable` and `libraries.items` are resolved to the SAME graph instances the
 * per-type stores hold (the in-memory analogue of the morph resolution the Eloquent arm does
 * over its polymorphic pivots).
 *
 * Writable types (albums/genres/devices/products/playlists) share their provider's store into
 * a persister, so a write is visible to a subsequent read.
 *
 * **Narrowed in-memory write surface (deliberate):** this arm is the dual-provider *read*
 * witness; only the five types above register a persister. The other model-backed types
 * (artists/tracks/users/favorites/libraries) declare writable operations for OpenAPI byte-compat
 * with the bundle, and the Eloquent arm's persister covers every one of them — but here they are
 * read-only, so a write to one of them is an unregistered-persister condition
 * ({@see \haddowg\JsonApiLaravel\DataPersister\DataPersisterRegistry::forType()} throws), not a
 * supported path. The dual-provider conformance/smoke suites only write albums/genres/playlists,
 * so no test crosses this boundary; register an {@see InMemoryDataPersister} for a type here if a
 * future in-memory write test needs it (the polymorphic favorites/libraries would additionally
 * need morph resolvers, as the bundle's reference persister does).
 */
final class MusicCatalogInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ascending type order — see MusicCatalogEloquentServiceProvider: the projected
        // OpenAPI `paths` follow discovery order, and the byte-compat contract requires them
        // to match the bundle's alphabetical-by-type descriptor order (decision 11).
        JsonApi::register([
            AlbumResource::class,
            ArtistResource::class,
            DeviceResource::class,
            FavoriteResource::class,
            GenreResource::class,
            LibraryResource::class,
            PlaylistResource::class,
            ProductResource::class,
            PublicProfileResource::class,
            TrackResource::class,
            UserResource::class,
            ReissueAlbum::class,
            SummarizeAlbums::class,
            UploadAlbumArtwork::class,
            // The two standalone-serializer types (no resource), registered last so their
            // paths follow the resources — the byte-compat contract (decision 3, bundle ADR 0024).
            ChartSerializer::class,
            CountrySerializer::class,
        ]);

        $graph = $this->buildGraph();

        // Read providers for every type (public-profiles reuses the users graph).
        // The custom FullTextSearch (filter[q]) runs on artists/albums/tracks via its
        // in-memory arm — the conformance witness for the Eloquent push-down arm.
        $fullTextArm = new ArrayFullTextSearchArm();

        $artists = new InMemoryDataProvider('artists', $graph['artists'], identify: self::identify(), assignId: self::assignId(), filterArms: [$fullTextArm]);
        JsonApi::provider($artists);

        $albums = new InMemoryDataProvider('albums', $graph['albums'], identify: self::identify(), assignId: self::assignId(), filterArms: [$fullTextArm]);
        JsonApi::provider($albums);
        $artistResolver = static fn(string $type, string $id): ?object => $type === 'artists' ? ($graph['artists'][$id] ?? null) : null;
        JsonApi::persister(new InMemoryDataPersister('albums', $albums->store(), static fn(): Album => new Album(), $artistResolver));

        $tracks = new InMemoryDataProvider('tracks', $graph['tracks'], identify: self::identify(), assignId: self::assignId(), filterArms: [$fullTextArm]);
        JsonApi::provider($tracks);

        $genres = new InMemoryDataProvider('genres', $graph['genres'], identify: self::identify());
        JsonApi::provider($genres);
        JsonApi::persister(new InMemoryDataPersister('genres', $genres->store(), static fn(): Genre => new Genre()));

        $devices = new InMemoryDataProvider('devices', $graph['devices'], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($devices);
        JsonApi::persister(new InMemoryDataPersister('devices', $devices->store(), static fn(): Device => new Device()));

        $products = new InMemoryDataProvider('products', $graph['products'], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($products);
        JsonApi::persister(new InMemoryDataPersister('products', $products->store(), static fn(): Product => new Product()));

        $users = new InMemoryDataProvider('users', $graph['users'], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($users);

        // public-profiles is a curated read-only second view of the SAME user rows.
        JsonApi::provider(new InMemoryDataProvider('public-profiles', $graph['users'], identify: self::identify()));

        $libraries = new InMemoryDataProvider('libraries', $graph['libraries'], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($libraries);

        $playlists = new InMemoryDataProvider('playlists', $graph['playlists'], identify: self::identify());
        JsonApi::provider($playlists);
        JsonApi::persister(new InMemoryDataPersister('playlists', $playlists->store(), static fn(): Playlist => new Playlist()));

        $favorites = new InMemoryDataProvider('favorites', $graph['favorites'], identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($favorites);

        // The two standalone-serializer types are served by the SAME custom providers the
        // Eloquent wiring uses — charts/countries are storage-orthogonal reference data, so
        // both provider arms read them identically (decision 3, bundle ADR 0024).
        JsonApi::provider(new ChartProvider());
        JsonApi::provider(new CountryProvider());
    }

    public function boot(): void
    {
        Gate::define('reissueAlbum', static fn(?AuthUser $user, object $album): bool => $user?->can_write === true);

        // The API-distinct playlist abilities (decision 7) — see the Eloquent provider.
        Gate::define('curate', [PlaylistApiPolicy::class, 'curate']);
        Gate::define('deletePlaylist', [PlaylistApiPolicy::class, 'deletePlaylist']);
        Gate::define('inspectOwner', [PlaylistApiPolicy::class, 'inspectOwner']);
    }

    /**
     * Builds the linked POPO object graph from the shared {@see Fixtures}: artists ↔ albums ↔
     * tracks, users → playlists/library, libraries → owner + mixed items, playlists → owner +
     * tracks, favorites → user + polymorphic favoritable — all sharing one set of instances.
     *
     * @return array{
     *     artists: array<int|string, Artist>, albums: array<int|string, Album>, tracks: array<int|string, Track>,
     *     genres: array<int|string, Genre>, devices: array<int|string, Device>, products: array<int|string, Product>,
     *     users: array<int|string, User>, libraries: array<int|string, Library>, playlists: array<int|string, Playlist>,
     *     favorites: array<int|string, Favorite>,
     * }
     */
    private function buildGraph(): array
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
                artwork: $row['artwork'],
                released_at: $row['released_at'],
                explicit: $row['explicit'],
                status: $row['status'],
                available_from: $row['available_from'],
                available_until: $row['available_until'],
                release_info: $row['release_info'],
                artist: $artist,
            );
            $albums[(string) $row['id']] = $album;
            if ($artist !== null) {
                $artist->albums[] = $album;
            }
        }

        $tracks = [];
        foreach (Fixtures::tracks() as $row) {
            $album = $row['album_id'] !== null ? ($albums[(string) $row['album_id']] ?? null) : null;
            $track = new Track(
                id: (string) $row['id'],
                title: $row['title'],
                track_number: $row['track_number'],
                length_seconds: $row['length_seconds'],
                explicit: $row['explicit'],
                genres: $row['genres'],
                preview_offset: $row['preview_offset'],
                album: $album,
            );
            $tracks[(string) $row['id']] = $track;
            if ($album !== null) {
                $album->tracks[] = $track;
            }
        }

        $genres = [];
        foreach (Fixtures::genres() as $row) {
            $genres[$row['id']] = new Genre(id: $row['id'], name: $row['name']);
        }

        $devices = [];
        foreach (Fixtures::devices() as $row) {
            $devices[$row['id']] = new Device(id: $row['id'], label: $row['label']);
        }

        $products = [];
        foreach (Fixtures::products() as $row) {
            $products[(string) $row['id']] = new Product(id: (string) $row['id'], name: $row['name']);
        }
        foreach (Fixtures::products() as $row) {
            if ($row['parent_id'] !== null) {
                $products[(string) $row['id']]->parent = $products[(string) $row['parent_id']] ?? null;
            }
        }

        $users = [];
        foreach (Fixtures::users() as $row) {
            $users[(string) $row['id']] = new User(
                id: (string) $row['id'],
                email: $row['email'],
                display_name: $row['display_name'],
                birth_date: $row['birth_date'],
                preferences: $row['preferences'],
                last_seen_ip: $row['last_seen_ip'],
                password: $row['password'],
                is_admin: $row['is_admin'],
            );
        }

        $byType = ['tracks' => $tracks, 'albums' => $albums, 'artists' => $artists];

        $libraries = [];
        foreach (Fixtures::libraries() as $row) {
            $owner = $row['owner_id'] !== null ? ($users[(string) $row['owner_id']] ?? null) : null;
            $items = [];
            foreach (Fixtures::libraryItems()[$row['id']] ?? [] as $pointer) {
                $member = $byType[$pointer['type']][$pointer['id']] ?? null;
                if ($member !== null) {
                    $items[] = $member;
                }
            }
            $library = new Library(id: (string) $row['id'], owner: $owner, items: $items);
            $libraries[(string) $row['id']] = $library;
            if ($owner !== null) {
                $owner->library = $library;
            }
        }

        $playlists = [];
        foreach (Fixtures::playlists() as $row) {
            $owner = $row['owner_id'] !== null ? ($users[(string) $row['owner_id']] ?? null) : null;
            $playlist = new Playlist(
                id: $row['id'],
                title: $row['title'],
                slug: $row['slug'],
                public: $row['public'],
                external_id: $row['external_id'],
                owner: $owner,
            );
            $playlists[$row['id']] = $playlist;
            if ($owner !== null) {
                $owner->playlists[] = $playlist;
            }
        }
        foreach (Fixtures::plainTracks() as $row) {
            $track = $tracks[(string) $row['track_id']] ?? null;
            if (isset($playlists[$row['playlist_id']]) && $track !== null) {
                $playlists[$row['playlist_id']]->tracks[] = $track;
            }
        }
        // The ordered pivot rows backing orderedTracks — the in-memory analogue of the
        // Eloquent mc_playlist_track pivot (the witness stores no pivot meta, but the members
        // must be present so both arms serve the same orderedTracks membership).
        foreach (Fixtures::orderedTracks() as $row) {
            $playlist = $playlists[$row['playlist_id']] ?? null;
            $track = $tracks[(string) $row['track_id']] ?? null;
            if ($playlist !== null && $track !== null) {
                $playlist->entries[] = new PlaylistEntry(
                    track: $track,
                    position: $row['position'],
                    weight: $row['weight'],
                    added_at: $row['added_at'],
                );
            }
        }

        $favorites = [];
        foreach (Fixtures::favorites() as $row) {
            $user = $row['user_id'] !== null ? ($users[(string) $row['user_id']] ?? null) : null;
            $target = null;
            if ($row['favoritable_type'] !== null && $row['favoritable_id'] !== null) {
                $target = $byType[$row['favoritable_type']][$row['favoritable_id']] ?? null;
            }
            $favorites[(string) $row['id']] = new Favorite(
                id: (string) $row['id'],
                favorited_at: $row['favorited_at'],
                user: $user,
                favoritable: $target,
            );
        }

        return \compact('artists', 'albums', 'tracks', 'genres', 'devices', 'products', 'users', 'libraries', 'playlists', 'favorites');
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
