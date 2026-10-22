<?php

declare(strict_types=1);

namespace Workbench\App\Timezone;

/**
 * The output-timezone fixtures both arms serve. Every value carries Europe/Paris, the
 * zone the app "stores" in, so a server default of UTC has something to convert.
 */
final class TimezoneFixtures
{
    public const string ZONE = 'Europe/Paris';

    /**
     * @return list<array{id: int, name: string, opened_at: \DateTimeImmutable}>
     */
    public static function venues(): array
    {
        return [
            ['id' => 1, 'name' => 'Le Bataclan', 'opened_at' => self::instant('2020-01-15 09:00:00')],
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function performers(): array
    {
        return [
            ['id' => 1, 'name' => 'Air'],
        ];
    }

    /**
     * `day` is local midnight and `doors_open` a bare local time, so converting either to
     * UTC would change the value rendered.
     *
     * @return list<array{id: int, title: string, starts_at: \DateTimeImmutable, day: \DateTimeImmutable, doors_open: \DateTimeImmutable, venue_id: int}>
     */
    public static function gigs(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Moon Safari Live',
                'starts_at' => self::instant('2024-06-01 18:00:00'),
                'day' => self::date('2024-06-01'),
                'doors_open' => self::time('19:30:00'),
                'venue_id' => 1,
            ],
        ];
    }

    /**
     * @return list<array{gig_id: int, performer_id: int, booked_at: \DateTimeImmutable}>
     */
    public static function bookings(): array
    {
        return [
            ['gig_id' => 1, 'performer_id' => 1, 'booked_at' => self::instant('2024-05-01 08:00:00')],
        ];
    }

    /**
     * A UTC wall-clock instant, moved into Paris.
     */
    public static function instant(string $utc): \DateTimeImmutable
    {
        $instant = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        \assert($instant instanceof \DateTimeImmutable);

        return $instant->setTimezone(new \DateTimeZone(self::ZONE));
    }

    public static function date(string $date): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone(self::ZONE));
        \assert($value instanceof \DateTimeImmutable);

        return $value;
    }

    public static function time(string $time): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat('!H:i:s', $time, new \DateTimeZone(self::ZONE));
        \assert($value instanceof \DateTimeImmutable);

        return $value;
    }
}
