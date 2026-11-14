<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider;

use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentFilterHandler;
use haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys\BinaryUuid;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A key compared through a model no type with an encoder serves goes through the key's cast
 * only when that cast is a value object. A primitive, enum, encrypted or JSON cast leaves the
 * client's value as sent, so matching on those keys is unchanged.
 *
 * @internal
 */
#[CoversClass(EloquentFilterHandler::class)]
final class EloquentKeyCastTest extends TestCase
{
    private const string UUID = '01932f5e-1a2b-7c3d-8e4f-000000000001';

    #[Test]
    public function aValueObjectCastStoresTheKeyAsTheModelWould(): void
    {
        self::assertSame(\hex2bin('01932f5e1a2b7c3d8e4f000000000001'), $this->storageKey(self::model(BinaryUuid::class), self::UUID));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonValueObjectCasts(): iterable
    {
        yield 'integer' => ['integer', '42'];
        yield 'string' => ['string', 'abc'];
        yield 'a uuid kept as its string' => ['string', self::UUID];
        yield 'encrypted' => ['encrypted', 'secret'];
        yield 'json' => ['json', 'abc'];
        yield 'datetime' => ['datetime', '2026-01-01 00:00:00'];
    }

    #[Test]
    #[DataProvider('nonValueObjectCasts')]
    public function anyOtherCastLeavesTheValueAsSent(string $cast, string $value): void
    {
        self::assertSame($value, $this->storageKey(self::model($cast), $value));
    }

    #[Test]
    public function aKeyWithNoCastLeavesTheValueAsSent(): void
    {
        self::assertSame(self::UUID, $this->storageKey(self::model(null), self::UUID));
    }

    private static function model(?string $cast): Model
    {
        $model = new class extends Model {
            /**
             * The key's cast, static so the instance the handler builds to store a key sees it.
             *
             * @var array<string, string>
             */
            public static array $declared = [];

            public function getCasts(): array
            {
                return self::$declared;
            }
        };
        $model::$declared = $cast !== null ? ['id' => $cast] : [];

        return $model;
    }

    private function storageKey(Model $model, string $value): mixed
    {
        $handler = new EloquentFilterHandler([], static fn(string $class, ?string $type): array => []);

        return (new \ReflectionMethod($handler, 'storageKey'))->invoke($handler, $model, $value);
    }
}
