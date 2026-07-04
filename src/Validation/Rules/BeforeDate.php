<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\Before} bound — an upper
 * date bound whose limit may be a PHP closure resolved at validation time, which the
 * native `before:` rule (literals/fields only) cannot express. The value is coerced to
 * a `\DateTimeImmutable`; an absent, empty or unparseable value is skipped. The twin of
 * {@see AfterDate}.
 */
final class BeforeDate implements ValidationRule
{
    use ResolvesDateBound;

    /**
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     */
    public function __construct(private readonly \DateTimeInterface|\Closure $bound) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $date = self::toDateTime($value);
        if ($date === null) {
            return;
        }

        $limit = self::resolveBound($this->bound);
        if ($date >= $limit) {
            $fail('validation.before')->translate(['date' => $limit->format(\DateTimeInterface::ATOM)]);
        }
    }
}
