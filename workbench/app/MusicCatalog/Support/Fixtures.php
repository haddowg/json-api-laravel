<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Support;

/**
 * The canonical music-catalog seed data (decision 14), shared by BOTH the Eloquent
 * ({@see \Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider}) and the
 * in-memory ({@see \Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider})
 * wirings so the two suites read identical rows/ids — the dual-provider conformance premise.
 *
 * Keys are the storage columns (snake_case). Dates are `\DateTimeImmutable` so both a POPO
 * (assigned directly) and an Eloquent `create()` (cast on write) accept them.
 */
final class Fixtures
{
    public const string DEVICE_ONE = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public const string DEVICE_TWO = '01BX5ZZKBKACTAV9WEVGEMMVRZ';

    public const string PLAYLIST_ONE = '11111111-1111-4111-8111-111111111111';

    /**
     * @return list<array{id: int, name: string, slug: string, website: ?string, bio: ?string, track_count: int, created_at: \DateTimeImmutable}>
     */
    public static function artists(): array
    {
        return [
            ['id' => 1, 'name' => 'Radiohead', 'slug' => 'radiohead', 'website' => 'https://radiohead.com', 'bio' => 'An English rock band formed in Abingdon.', 'track_count' => 3, 'created_at' => new \DateTimeImmutable('1985-01-01T00:00:00+00:00')],
            ['id' => 2, 'name' => 'Portishead', 'slug' => 'portishead', 'website' => null, 'bio' => null, 'track_count' => 2, 'created_at' => new \DateTimeImmutable('1991-01-01T00:00:00+00:00')],
        ];
    }

    /**
     * @return list<array{id: int, artist_id: ?int, title: string, average_rating: ?float, artwork: ?string, released_at: \DateTimeImmutable, explicit: bool, status: string, available_from: ?\DateTimeImmutable, available_until: ?\DateTimeImmutable, release_info: ?array<string, mixed>}>
     */
    public static function albums(): array
    {
        return [
            ['id' => 1, 'artist_id' => 1, 'title' => 'OK Computer', 'average_rating' => 9.8, 'artwork' => null, 'released_at' => new \DateTimeImmutable('1997-05-21T00:00:00+00:00'), 'explicit' => false, 'status' => 'released', 'available_from' => new \DateTimeImmutable('1997-05-21'), 'available_until' => null, 'release_info' => ['label' => 'Parlophone', 'catalogueNumber' => 'NODATA 01']],
            ['id' => 2, 'artist_id' => 2, 'title' => 'Dummy', 'average_rating' => 9.1, 'artwork' => null, 'released_at' => new \DateTimeImmutable('1994-08-22T00:00:00+00:00'), 'explicit' => false, 'status' => 'released', 'available_from' => new \DateTimeImmutable('1994-08-22'), 'available_until' => null, 'release_info' => null],
        ];
    }

    /**
     * @return list<array{id: int, album_id: ?int, title: string, track_number: int, length_seconds: int, explicit: bool, genres: list<string>, preview_offset: ?string}>
     */
    public static function tracks(): array
    {
        return [
            ['id' => 1, 'album_id' => 1, 'title' => 'Airbag', 'track_number' => 1, 'length_seconds' => 284, 'explicit' => false, 'genres' => ['alt-rock'], 'preview_offset' => '00:00:30'],
            ['id' => 2, 'album_id' => 1, 'title' => 'Paranoid Android', 'track_number' => 2, 'length_seconds' => 383, 'explicit' => false, 'genres' => ['alt-rock'], 'preview_offset' => null],
            ['id' => 3, 'album_id' => 2, 'title' => 'Roads', 'track_number' => 4, 'length_seconds' => 302, 'explicit' => false, 'genres' => ['trip-hop'], 'preview_offset' => null],
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function genres(): array
    {
        return [
            ['id' => 'trip-hop', 'name' => 'Trip Hop'],
            ['id' => 'alt-rock', 'name' => 'Alternative Rock'],
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function devices(): array
    {
        return [
            ['id' => self::DEVICE_ONE, 'label' => 'Living Room Speaker'],
            ['id' => self::DEVICE_TWO, 'label' => 'Kitchen Radio'],
        ];
    }

    /**
     * @return list<array{id: int, parent_id: ?int, name: string}>
     */
    public static function products(): array
    {
        return [
            ['id' => 1, 'parent_id' => null, 'name' => 'Vinyl Reissue Box'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Deluxe Vinyl Reissue'],
        ];
    }

    /**
     * @return list<array{id: int, email: string, display_name: string, birth_date: ?\DateTimeImmutable, preferences: ?array<string, mixed>, last_seen_ip: ?string, password: ?string, is_admin: bool}>
     */
    public static function users(): array
    {
        return [
            ['id' => 1, 'email' => 'ada@music.example', 'display_name' => 'Ada', 'birth_date' => new \DateTimeImmutable('1990-01-01'), 'preferences' => ['theme' => 'dark'], 'last_seen_ip' => '203.0.113.7', 'password' => 'secret-password', 'is_admin' => true],
            ['id' => 2, 'email' => 'grace@music.example', 'display_name' => 'Grace', 'birth_date' => null, 'preferences' => null, 'last_seen_ip' => null, 'password' => 'another-password', 'is_admin' => false],
        ];
    }

    /**
     * @return list<array{id: int, owner_id: ?int}>
     */
    public static function libraries(): array
    {
        return [
            ['id' => 1, 'owner_id' => 1],
        ];
    }

    /**
     * The mixed polymorphic members of each library (the over-parity headline): a track, an
     * album and an artist. `type` is the JSON:API type; the wiring maps it to a morph alias.
     *
     * @return array<int, list<array{type: string, id: string}>>
     */
    public static function libraryItems(): array
    {
        return [
            1 => [
                ['type' => 'tracks', 'id' => '1'],
                ['type' => 'albums', 'id' => '2'],
                ['type' => 'artists', 'id' => '1'],
            ],
        ];
    }

    /**
     * @return list<array{id: string, owner_id: ?int, title: string, slug: string, public: bool, external_id: ?string}>
     */
    public static function playlists(): array
    {
        return [
            ['id' => self::PLAYLIST_ONE, 'owner_id' => 1, 'title' => 'Morning Mix', 'slug' => 'morning-mix', 'public' => true, 'external_id' => null],
        ];
    }

    /**
     * The ordered pivot rows (position/weight/added_at) for `playlists.orderedTracks`.
     *
     * @return list<array{playlist_id: string, track_id: int, position: int, weight: ?int, added_at: ?\DateTimeImmutable}>
     */
    public static function orderedTracks(): array
    {
        return [
            ['playlist_id' => self::PLAYLIST_ONE, 'track_id' => 1, 'position' => 1, 'weight' => 1, 'added_at' => new \DateTimeImmutable('2024-01-01T00:00:00+00:00')],
            ['playlist_id' => self::PLAYLIST_ONE, 'track_id' => 2, 'position' => 2, 'weight' => 2, 'added_at' => new \DateTimeImmutable('2024-01-02T00:00:00+00:00')],
        ];
    }

    /**
     * The bare-join rows for the plain `playlists.tracks` relation.
     *
     * @return list<array{playlist_id: string, track_id: int}>
     */
    public static function plainTracks(): array
    {
        return [
            ['playlist_id' => self::PLAYLIST_ONE, 'track_id' => 1],
        ];
    }

    /**
     * @return list<array{id: int, user_id: ?int, favorited_at: ?\DateTimeImmutable, favoritable_type: ?string, favoritable_id: ?string}>
     */
    public static function favorites(): array
    {
        return [
            ['id' => 1, 'user_id' => 1, 'favorited_at' => new \DateTimeImmutable('2024-02-01T00:00:00+00:00'), 'favoritable_type' => 'tracks', 'favoritable_id' => '1'],
            ['id' => 2, 'user_id' => 1, 'favorited_at' => new \DateTimeImmutable('2024-02-02T00:00:00+00:00'), 'favoritable_type' => 'albums', 'favoritable_id' => '2'],
        ];
    }
}
