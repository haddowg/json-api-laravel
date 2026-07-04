<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * The canonical music-catalog seed data, shared by BOTH the in-memory
 * ({@see \Workbench\App\Providers\WorkbenchServiceProvider}) and Eloquent
 * ({@see \Workbench\App\Providers\EloquentWorkbenchServiceProvider}) provider wirings
 * so the two suites read identical rows/ids — the premise that lets a dual-provider
 * conformance failure localize to one provider's execution (blueprint §5.2).
 *
 * Keys are the storage **columns** (snake_case), matching the resource field
 * `storedAs()` map: the same column name resolves off an in-memory POPO's property
 * and off an Eloquent model's cast attribute. Dates are `\DateTimeImmutable` so both
 * a POPO (assigned directly) and an Eloquent `create()` (cast on write) accept them.
 */
final class Fixtures
{
    /**
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
                'created_at' => new \DateTimeImmutable('1991-01-01T00:00:00+00:00'),
            ],
        ];
    }

    /**
     * @return list<array{id: int, title: string, average_rating: ?float, status: string, explicit: bool, available_from: ?\DateTimeImmutable, released_at: ?\DateTimeImmutable}>
     */
    public static function albums(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'OK Computer',
                'average_rating' => 9.8,
                'status' => 'released',
                'explicit' => false,
                'available_from' => new \DateTimeImmutable('1997-05-21'),
                'released_at' => new \DateTimeImmutable('1997-05-21T00:00:00+00:00'),
            ],
            [
                'id' => 2,
                'title' => 'Dummy',
                'average_rating' => 9.1,
                'status' => 'released',
                'explicit' => false,
                'available_from' => new \DateTimeImmutable('1994-08-22'),
                'released_at' => new \DateTimeImmutable('1994-08-22T00:00:00+00:00'),
            ],
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
}
