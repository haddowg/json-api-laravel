<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\After} bound — a lower
 * date bound the native `after:` rule cannot express because its bound may be a PHP
 * closure (e.g. "now") resolved at validation time rather than a literal or a sibling
 * field. The value is coerced to a `\DateTimeImmutable`; an absent, empty or
 * unparseable value is left to the presence/format layers and skipped. The bound is
 * resolved on every run, so a closure bound reflects the moment of the request.
 */
final class AfterDate implements ValidationRule
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
        if ($date <= $limit) {
            $fail('validation.after')->translate(['date' => $limit->format(\DateTimeInterface::ATOM)]);
        }
    }
}
