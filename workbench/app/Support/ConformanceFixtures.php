<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * The richer read-conformance seed data, shared by BOTH the in-memory
 * ({@see \Workbench\App\Providers\ConformanceInMemoryServiceProvider}) and Eloquent
 * ({@see \Workbench\Database\Seeders\ConformanceSeeder}) wirings so the two
 * conformance suites read byte-identical rows and a divergent result localizes to
 * one provider's execution (the dual-provider referee premise, blueprint §5.2).
 *
 * Distinct from the minimal {@see Fixtures} (2 rows/type) the Phase-0 feature suite
 * asserts against — this set is deliberately larger and edge-heavy so the shared
 * assertions exercise:
 *  - **null attributes** (artists 2/4/6 have a null `website`/`bio`; albums 4/7 a
 *    null `average_rating`; album 6 a null `available_from`);
 *  - **multi-field sort tie-breaks** (albums repeat `status`, broken by the unique
 *    `title` — a wrong sort composition reorders visibly);
 *  - **mixed-case strings** (`aphex twin`, `ARCA`, `in rainbows`, `MEZZANINE`-style
 *    casing) so the ASCII case-insensitive `like`/`starts`/`ends` contract and the
 *    case-sensitive byte-order sort both prove out identically on both providers;
 *  - **numeric / string / date / bool attribute types** across the two resources.
 *
 * Keys are the storage **columns** (snake_case), matching each resource field's
 * `storedAs()` map, so the same column resolves off an in-memory POPO's property and
 * off an Eloquent model's cast attribute. Dates are `\DateTimeImmutable` so a POPO
 * (assigned directly) and an Eloquent `create()` (cast on write) both accept them.
 *
 * Every collection assertion in the suite pins a **total** order (a unique final
 * sort key) or asserts a set, so no assertion depends on an undefined tie order — the
 * one exception is deliberately probed by the multi-field-sort tie-break tests, whose
 * secondary key makes the order total.
 */
final class ConformanceFixtures
{
    /**
     * Six artists. `created_at` is unique (the deterministic count-free default sort);
     * `website`/`bio` are null on three (null-attribute + WhereNull coverage); the
     * names mix leading upper/lower case (`ARCA` before `aphex twin` in byte order).
     *
     * @return list<array{id: int, name: string, slug: string, website: ?string, bio: ?string, track_count: int, created_at: \DateTimeImmutable}>
     */
    public static function artists(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Radiohead',
                'slug' => 'radiohead',
                'website' => 'https://radiohead.com',
                'bio' => 'An English rock band formed in Abingdon.',
                'track_count' => 3,
                'created_at' => new \DateTimeImmutable('1985-01-01T00:00:00+00:00'),
            ],
            [
                'id' => 2,
                'name' => 'Portishead',
                'slug' => 'portishead',
                'website' => null,
                'bio' => null,
                'track_count' => 2,
                'created_at' => new \DateTimeImmutable('1991-02-01T00:00:00+00:00'),
            ],
            [
                'id' => 3,
                'name' => 'Massive Attack',
                'slug' => 'massive-attack',
                'website' => 'https://massiveattack.co.uk',
                'bio' => 'A Bristol trip-hop collective.',
                'track_count' => 5,
                'created_at' => new \DateTimeImmutable('1988-03-01T00:00:00+00:00'),
            ],
            [
                'id' => 4,
                'name' => 'aphex twin',
                'slug' => 'aphex-twin',
                'website' => null,
                'bio' => null,
                'track_count' => 0,
                'created_at' => new \DateTimeImmutable('1985-06-01T00:00:00+00:00'),
            ],
            [
                'id' => 5,
                'name' => 'Boards of Canada',
                'slug' => 'boards-of-canada',
                'website' => 'https://boardsofcanada.com',
                'bio' => null,
                'track_count' => 4,
                'created_at' => new \DateTimeImmutable('1995-04-01T00:00:00+00:00'),
            ],
            [
                'id' => 6,
                'name' => 'ARCA',
                'slug' => 'arca',
                'website' => null,
                'bio' => 'A Venezuelan electronic producer.',
                'track_count' => 1,
                'created_at' => new \DateTimeImmutable('2012-05-01T00:00:00+00:00'),
            ],
        ];
    }

    /**
     * Seven albums. `released_at` is unique (the deterministic default DESC sort);
     * `status` repeats (released ×4, archived ×2, draft ×1 — the multi-field-sort
     * tie source, broken by the unique `title`); `average_rating` is null on two
     * (WhereNull + null-attribute rendering); `explicit` carries both booleans;
     * `available_from` is null on one; titles mix case (`in rainbows`, `amnesiac`).
     *
     * `artist_id` links each album to its {@see artists()} owner, shaped for the Phase-3a
     * relationship-read batch edge cases: Radiohead (1) owns FOUR albums (1/3/6/7 — the
     * many/window case), Portishead (2) owns ONE (2 — the singleton), Massive Attack (3)
     * owns TWO (4/5), and artists 4/5/6 own NONE (the empty to-many). It is the FK the
     * Eloquent `belongsTo`/`hasMany` and the in-memory object graph both key on.
     *
     * @return list<array{id: int, artist_id: ?int, title: string, average_rating: ?float, status: string, explicit: bool, available_from: ?\DateTimeImmutable, released_at: \DateTimeImmutable}>
     */
    public static function albums(): array
    {
        return [
            [
                'id' => 1,
                'artist_id' => 1,
                'title' => 'OK Computer',
                'average_rating' => 9.8,
                'status' => 'released',
                'explicit' => false,
                'available_from' => new \DateTimeImmutable('1997-05-21'),
                'released_at' => new \DateTimeImmutable('1997-05-21T00:00:00+00:00'),
            ],
            [
                'id' => 2,
                'artist_id' => 2,
                'title' => 'Dummy',
                'average_rating' => 9.1,
                'status' => 'released',
                'explicit' => false,
                'available_from' => new \DateTimeImmutable('1994-08-22'),
                'released_at' => new \DateTimeImmutable('1994-08-22T00:00:00+00:00'),
            ],
            [
                'id' => 3,
                'artist_id' => 1,
                'title' => 'Kid A',
                'average_rating' => 9.5,
                'status' => 'released',
                'explicit' => true,
                'available_from' => new \DateTimeImmutable('2000-10-02'),
                'released_at' => new \DateTimeImmutable('2000-10-02T00:00:00+00:00'),
            ],
            [
                'id' => 4,
                'artist_id' => 3,
                'title' => 'Mezzanine',
                'average_rating' => null,
                'status' => 'archived',
                'explicit' => true,
                'available_from' => new \DateTimeImmutable('1998-04-20'),
                'released_at' => new \DateTimeImmutable('1998-04-20T00:00:00+00:00'),
            ],
            [
                'id' => 5,
                'artist_id' => 3,
                'title' => 'Blue Lines',
                'average_rating' => 8.7,
                'status' => 'archived',
                'explicit' => false,
                'available_from' => new \DateTimeImmutable('1991-04-08'),
                'released_at' => new \DateTimeImmutable('1991-04-08T00:00:00+00:00'),
            ],
            [
                'id' => 6,
                'artist_id' => 1,
                'title' => 'in rainbows',
                'average_rating' => 9.0,
                'status' => 'released',
                'explicit' => false,
                'available_from' => null,
                'released_at' => new \DateTimeImmutable('2007-10-10T00:00:00+00:00'),
            ],
            [
                'id' => 7,
                'artist_id' => 1,
                'title' => 'amnesiac',
                'average_rating' => null,
                'status' => 'draft',
                'explicit' => true,
                'available_from' => new \DateTimeImmutable('2001-06-04'),
                'released_at' => new \DateTimeImmutable('2001-06-04T00:00:00+00:00'),
            ],
        ];
    }

    /**
     * Eight cursor widgets, shared by BOTH cursor (keyset) conformance concretes so the
     * SQL push-down and the in-memory witness referee the SAME rows (bundle ADR 0063).
     * Deliberately shaped to exercise every keyset branch:
     *  - `category` carries ties (guide ×4, news ×4), so the appended PK tiebreak is the
     *    ONLY thing keeping tied rows totally ordered;
     *  - `priority` is a NULLABLE int with nulls (ids 3, 6) mid-collection, so an asc
     *    page walks INTO the null bucket at the end and a desc page out of it at the
     *    start — the forced NULL=largest branch;
     *  - `released_at` is a NULLABLE datetime (nulls at ids 4, 6) for the date-keyed,
     *    typed-boundary case, with values straddling page boundaries.
     *
     * Ids are per-type sequential ints (1..8), matching the order the Eloquent
     * auto-increment assigns on insert.
     *
     * @return list<array{id: int, category: string, priority: ?int, released_at: ?\DateTimeImmutable}>
     */
    public static function cursorWidgets(): array
    {
        return [
            ['id' => 1, 'category' => 'guide', 'priority' => 30, 'released_at' => new \DateTimeImmutable('2024-01-05T00:00:00+00:00')],
            ['id' => 2, 'category' => 'guide', 'priority' => 10, 'released_at' => new \DateTimeImmutable('2024-03-01T00:00:00+00:00')],
            ['id' => 3, 'category' => 'news', 'priority' => null, 'released_at' => new \DateTimeImmutable('2024-02-10T00:00:00+00:00')],
            ['id' => 4, 'category' => 'guide', 'priority' => 30, 'released_at' => null],
            ['id' => 5, 'category' => 'news', 'priority' => 20, 'released_at' => new \DateTimeImmutable('2024-01-20T00:00:00+00:00')],
            ['id' => 6, 'category' => 'news', 'priority' => null, 'released_at' => null],
            ['id' => 7, 'category' => 'guide', 'priority' => 10, 'released_at' => new \DateTimeImmutable('2024-05-01T00:00:00+00:00')],
            ['id' => 8, 'category' => 'news', 'priority' => 20, 'released_at' => new \DateTimeImmutable('2024-04-15T00:00:00+00:00')],
        ];
    }

    /**
     * Five tracks — the far side of the Phase-3b playlist pivot relation. Titles are
     * distinct (the `orderedTrackTitled` WhereThrough leaf) and `released_at` is a sortable
     * key for windowed related fetches.
     *
     * @return list<array{id: int, title: string, released_at: \DateTimeImmutable}>
     */
    public static function tracks(): array
    {
        return [
            ['id' => 1, 'title' => 'Airbag', 'released_at' => new \DateTimeImmutable('1997-05-21T00:00:00+00:00')],
            ['id' => 2, 'title' => 'Paranoid Android', 'released_at' => new \DateTimeImmutable('1997-05-21T00:00:00+00:00')],
            ['id' => 3, 'title' => 'Karma Police', 'released_at' => new \DateTimeImmutable('1997-05-21T00:00:00+00:00')],
            ['id' => 4, 'title' => 'Everything In Its Right Place', 'released_at' => new \DateTimeImmutable('2000-10-02T00:00:00+00:00')],
            ['id' => 5, 'title' => 'Idioteque', 'released_at' => new \DateTimeImmutable('2000-10-02T00:00:00+00:00')],
        ];
    }

    /**
     * Three playlists shaped for the pivot + existence + window edges:
     *  - `1` (Best Of) owns FOUR ordered tracks — the many/window case, with a TIED `position`
     *    (tracks 2 and 3 both at position 2) so the appended `id` tiebreak is exercised;
     *  - `2` (Solo) owns ONE — the singleton;
     *  - `3` (Empty) owns NONE — the empty partition + `withoutOrderedTracks` case.
     *
     * @return list<array{id: int, title: string, public: bool}>
     */
    public static function playlists(): array
    {
        return [
            ['id' => 1, 'title' => 'Best Of', 'public' => true],
            ['id' => 2, 'title' => 'Solo', 'public' => true],
            ['id' => 3, 'title' => 'Empty', 'public' => false],
        ];
    }

    /**
     * The `playlist_track` pivot rows: `position` distinct per playlist except playlist 1's
     * tied pair (tracks 2 and 3 at position 2), `weight >= position` throughout, and a
     * server-owned `added_at`. Track 1 (Airbag) is shared across playlists 1 AND 2 — the
     * belongsToMany fan-out proving a semi-join returns each parent once.
     *
     * @return list<array{playlist_id: int, track_id: int, position: int, weight: int, added_at: \DateTimeImmutable}>
     */
    public static function playlistTracks(): array
    {
        return [
            ['playlist_id' => 1, 'track_id' => 1, 'position' => 1, 'weight' => 1, 'added_at' => new \DateTimeImmutable('2024-01-01T00:00:00+00:00')],
            ['playlist_id' => 1, 'track_id' => 2, 'position' => 2, 'weight' => 2, 'added_at' => new \DateTimeImmutable('2024-01-02T00:00:00+00:00')],
            ['playlist_id' => 1, 'track_id' => 3, 'position' => 2, 'weight' => 3, 'added_at' => new \DateTimeImmutable('2024-01-03T00:00:00+00:00')],
            ['playlist_id' => 1, 'track_id' => 4, 'position' => 4, 'weight' => 4, 'added_at' => new \DateTimeImmutable('2024-01-04T00:00:00+00:00')],
            ['playlist_id' => 2, 'track_id' => 1, 'position' => 1, 'weight' => 5, 'added_at' => new \DateTimeImmutable('2024-02-01T00:00:00+00:00')],
        ];
    }

    /**
     * Three genres on a natural string key — the non-numeric-id fetch-one path and an
     * empty sort vocabulary (a `?sort` against `genres` is `SORTING_UNSUPPORTED`).
     *
     * @return list<array{id: string, name: string}>
     */
    public static function genres(): array
    {
        return [
            ['id' => 'trip-hop', 'name' => 'Trip Hop'],
            ['id' => 'alt-rock', 'name' => 'Alternative Rock'],
            ['id' => 'ambient', 'name' => 'Ambient'],
        ];
    }
}
