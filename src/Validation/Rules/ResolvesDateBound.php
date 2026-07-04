<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

/**
 * Shared date coercion for the closure-bounded date rules
 * ({@see AfterDate}/{@see BeforeDate}/{@see BetweenDates}): coerce a raw wire value to
 * a comparable instant, and resolve a fixed-or-closure bound to a
 * `\DateTimeImmutable` at validation time (so a deferred "now" bound reflects the
 * request).
 */
trait ResolvesDateBound
{
    /**
     * Coerces a raw attribute value to a comparable instant, or `null` when it is not a
     * non-empty date string — presence and format are other layers' concern, so a bound
     * check does not apply to a value it cannot read as a date.
     */
    private static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Resolves a fixed or closure bound to a `\DateTimeImmutable`, evaluating a closure
     * now so a deferred bound reflects validation time.
     *
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     */
    private static function resolveBound(\DateTimeInterface|\Closure $bound): \DateTimeImmutable
    {
        $resolved = $bound instanceof \Closure ? $bound() : $bound;
        if (!$resolved instanceof \DateTimeInterface) {
            throw new \LogicException('A date constraint bound closure must return a \DateTimeInterface.');
        }

        return \DateTimeImmutable::createFromInterface($resolved);
    }
}
