<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\NaiveDateTimeStorage;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NaiveDateTimeStorage::class)]
final class NaiveDateTimeStorageTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = \date_default_timezone_get();
        \date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        \date_default_timezone_set($this->previousTimezone);
    }

    #[Test]
    public function aValueMovesIntoTheDefaultTimezoneReadAtConversion(): void
    {
        $storage = new NaiveDateTimeStorage();
        $value = new \DateTimeImmutable('2026-10-01T16:00:00Z');

        self::assertSame('2026-10-01 12:00:00', $storage->toStorage($value)->format('Y-m-d H:i:s'));

        \date_default_timezone_set('Europe/Paris');
        self::assertSame('2026-10-01 18:00:00', $storage->toStorage($value)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function datetimeCastsAndDateColumnsAreNaiveAndDateCastsAreNot(): void
    {
        $model = new class extends Model {
            protected $casts = [
                'starts_at' => 'immutable_datetime',
                'ends_at' => 'datetime:Y-m-d H:i',
                'day' => 'immutable_date',
                'birthday' => 'date',
                'title' => 'string',
            ];
        };
        $storage = new NaiveDateTimeStorage();

        self::assertTrue($storage->isNaive($model, 'starts_at'));
        self::assertTrue($storage->isNaive($model, 'ends_at'));
        self::assertTrue($storage->isNaive($model, 'created_at'));
        self::assertFalse($storage->isNaive($model, 'day'));
        self::assertFalse($storage->isNaive($model, 'title'));
        self::assertFalse($storage->isNaive($model, 'uncast'));

        self::assertTrue($storage->isDateOnly($model, 'day'));
        self::assertTrue($storage->isDateOnly($model, 'birthday'));
        self::assertFalse($storage->isDateOnly($model, 'starts_at'));
    }
}
