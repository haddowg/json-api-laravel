<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Moves a date-time into the zone Eloquent stores a datetime-cast column in, before it
 * is bound to a query or written to a model.
 *
 * Eloquent formats a `\DateTimeInterface` as `Y-m-d H:i:s` for a query binding or a
 * datetime cast, dropping the offset, and reads the column back in PHP's default
 * timezone. So the column holds local time in that zone, and a client value like
 * `2026-10-01T15:00:00Z` has to become the same instant in that zone first. The zone
 * is read when each value is converted, never cached, because an application may
 * switch the default timezone per request.
 *
 * Only the built-in datetime casts and the model's date columns convert. A `date` cast
 * is compared by calendar day instead, because moving a midnight bound into another
 * zone can change its day. A column with a custom cast or no cast is left alone.
 *
 * @internal
 */
final class NaiveDateTimeStorage
{
    private const array DATE_TIME_CASTS = ['datetime', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime', 'timestamp'];

    private const array DATE_CASTS = ['date', 'immutable_date'];

    public function zone(): \DateTimeZone
    {
        return new \DateTimeZone(\date_default_timezone_get());
    }

    /**
     * Whether `$column` of `$model` stores a date-time without its offset.
     */
    public function isNaive(Model $model, string $column): bool
    {
        if ($model->hasCast($column, self::DATE_CASTS)) {
            return false;
        }

        return $model->hasCast($column, self::DATE_TIME_CASTS) || \in_array($column, $model->getDates(), true);
    }

    /**
     * Whether `$column` of `$model` holds a calendar date.
     */
    public function isDateOnly(Model $model, string $column): bool
    {
        return $model->hasCast($column, self::DATE_CASTS);
    }

    public function toStorage(\DateTimeInterface $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($value)->setTimezone($this->zone());
    }
}
