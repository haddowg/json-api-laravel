<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Http;

use haddowg\JsonApiLaravel\Http\SunsetDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SunsetDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'IMF-fixdate' => ['Sat, 01 Jan 2028 00:00:00 GMT', '2028-01-01'];
        yield 'RFC 850' => ['Saturday, 01-Jan-28 00:00:00 GMT', '2028-01-01'];
        yield 'asctime' => ['Sat Jan  1 00:00:00 2028', '2028-01-01'];
        yield 'structured field' => ['@1830297600', '2028-01-01'];
        yield 'ISO 8601 date' => ['2028-01-01', '2028-01-01'];
        yield 'ISO 8601 date-time' => ['2028-01-01T23:30:00Z', '2028-01-01'];
    }

    #[Test]
    #[DataProvider('dates')]
    public function itReadsTheDate(string $value, string $expected): void
    {
        self::assertSame($expected, SunsetDate::parse($value)?->format('Y-m-d'));
    }

    #[Test]
    public function aValueThatIsNotADateReadsAsNone(): void
    {
        self::assertNull(SunsetDate::parse(null));
        self::assertNull(SunsetDate::parse(''));
        self::assertNull(SunsetDate::parse('eventually'));
        self::assertNull(SunsetDate::parse('+1 year'));
        self::assertNull(SunsetDate::parse('2028-02-30'));
    }
}
