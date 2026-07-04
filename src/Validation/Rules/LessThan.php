<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\ExclusiveMax} — a literal
 * exclusive numeric upper bound, the twin of {@see GreaterThan}. Native `lt`/`lte` are
 * field-vs-field only, so this ships the literal exclusive-maximum rule. A non-numeric
 * value is skipped.
 */
final class LessThan implements ValidationRule
{
    public function __construct(private readonly int|float $value) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_numeric($value)) {
            return;
        }

        if (!((float) $value < $this->value)) {
            $fail('validation.lt.numeric')->translate(['value' => $this->value]);
        }
    }
}
