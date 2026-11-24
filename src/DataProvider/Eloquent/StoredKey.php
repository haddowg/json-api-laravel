<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A key as the database stores it, which is what every query binds and every in-memory match
 * compares. An attribute behind a value-object cast reads as an object, such as a UUID, while
 * the database holds what the cast's `set()` returns, such as its 16 bytes. Binding the object
 * or comparing it as a string matches neither, so a key crosses into a query, or into a map
 * key, through here.
 *
 * @internal
 */
final class StoredKey
{
    /**
     * Each id column's comparison family on PostgreSQL, keyed by connection, table and column.
     *
     * @var array<string, 'int'|'uuid'|'text'>
     */
    private static array $columnFamilies = [];
    /**
     * `$value` as `$model` stores `$attribute`: set through the attribute's value-object cast,
     * a class implementing `CastsAttributes` or `CastsInboundAttributes`, when it declares one,
     * and as given otherwise. A primitive, enum, encrypted or JSON cast leaves the value as
     * given, since setting it would store nothing a sent id could match. A `Stringable` the
     * cast doesn't take is its string.
     */
    public static function of(Model $model, string $attribute, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (self::castsValueObject($model, $attribute)) {
            try {
                $stored = $model->newInstance();
                $stored->setAttribute($attribute, $value);

                return $stored->getAttributes()[$attribute] ?? $value;
            } catch (\Throwable) {
                // A cast that refuses the value leaves it as given.
            }
        }

        return $value instanceof \Stringable ? (string) $value : $value;
    }

    /**
     * What `$model` holds for `$attribute` as stored, read past its cast.
     */
    public static function on(Model $model, string $attribute): mixed
    {
        return $model->getAttributes()[$attribute] ?? null;
    }

    /**
     * The key `$model` is stored under, read past its cast.
     */
    public static function keyOf(Model $model): mixed
    {
        return self::on($model, $model->getKeyName());
    }

    /**
     * A stored key as a string to compare or index by, or `null` for anything that holds no
     * key. An integer and its digit string compare equal, as the database compares them.
     */
    public static function comparable(mixed $value): ?string
    {
        return match (true) {
            \is_string($value), \is_int($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }

    /**
     * Whether `$model` stores `$attribute` through a value-object cast.
     */
    public static function castsValueObject(Model $model, string $attribute): bool
    {
        $cast = $model->getCasts()[$attribute] ?? null;
        $class = \is_string($cast) ? \explode(':', $cast, 2)[0] : null;

        return $class !== null && \class_exists($class) && (\is_a($class, CastsAttributes::class, true) || \is_a($class, CastsInboundAttributes::class, true));
    }

    /**
     * What a column holding ids compares as: `int` for an attribute cast to an integer, and on
     * PostgreSQL, which compares a column only with values of its own kind, `int` or `uuid` for
     * a column of that type, read from the schema once per column. Anything else is `text`.
     *
     * @return 'int'|'uuid'|'text'
     */
    public static function columnFamily(Model $model, string $attribute, string $table): string
    {
        if (\in_array($model->getCasts()[$attribute] ?? null, ['int', 'integer'], true)) {
            return 'int';
        }
        $connection = $model->getConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            return 'text';
        }

        $cacheKey = $connection->getName() . "\0" . $table . "\0" . $attribute;
        if (!isset(self::$columnFamilies[$cacheKey])) {
            try {
                $type = \strtolower($connection->getSchemaBuilder()->getColumnType($table, $attribute));
            } catch (\Throwable) {
                $type = '';
            }
            self::$columnFamilies[$cacheKey] = match (true) {
                \in_array($type, ['int2', 'int4', 'int8', 'smallint', 'integer', 'bigint'], true) => 'int',
                $type === 'uuid' => 'uuid',
                default => 'text',
            };
        }

        return self::$columnFamilies[$cacheKey];
    }

    /**
     * Forgets the column types read from the schema, for a test that recreates a table with
     * other types.
     *
     * @internal
     */
    public static function forgetColumnTypes(): void
    {
        self::$columnFamilies = [];
    }
}
