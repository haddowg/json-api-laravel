<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\Between} inclusive date
 * range `[min, max]`, with the same value coercion and validation-time bound
 * resolution as {@see AfterDate}/{@see BeforeDate}. Either bound may be a closure
 * (resolved on every run), which the native `between:`/`after:`/`before:` rules cannot
 * express.
 */
final class BetweenDates implements ValidationRule
{
    use ResolvesDateBound;

    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $min
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $max
     */
    public function __construct(
        private readonly \DateTimeInterface|\Closure $min,
        private readonly \DateTimeInterface|\Closure $max,
    ) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $date = self::toDateTime($value);
        if ($date === null) {
            return;
        }

        $lower = self::resolveBound($this->min);
        $upper = self::resolveBound($this->max);
        if ($date < $lower || $date > $upper) {
            $fail('validation.between.numeric')->translate([
                'min' => $lower->format(\DateTimeInterface::ATOM),
                'max' => $upper->format(\DateTimeInterface::ATOM),
            ]);
        }
    }
}
