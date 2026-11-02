<?php

declare(strict_types=1);

namespace Workbench\App\StorageTimezone;

/**
 * Three shifts as local New York wall-clock times. Shift 1 starts at 16:00Z, shift 2
 * at 14:00Z and shift 3 at 13:00Z the next day.
 */
final class ShiftFixtures
{
    public const string ZONE = 'America/New_York';

    /**
     * @return list<array{id: int, starts_at: string, day: string}>
     */
    public static function rows(): array
    {
        return [
            ['id' => 1, 'starts_at' => '2026-10-01 12:00:00', 'day' => '2026-10-01'],
            ['id' => 2, 'starts_at' => '2026-10-01 10:00:00', 'day' => '2026-09-30'],
            ['id' => 3, 'starts_at' => '2026-10-02 09:00:00', 'day' => '2026-10-02'],
        ];
    }

    public static function at(string $localTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($localTime, new \DateTimeZone(self::ZONE));
    }
}
