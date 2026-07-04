<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\ExclusiveMin} — a literal
 * exclusive numeric lower bound. Laravel's native `gt`/`gte` compare a field against
 * *another field*, never a literal, so there is no stock exclusive-minimum rule; this
 * ships one. A non-numeric value is left to the numeric/type layers and skipped.
 */
final class GreaterThan implements ValidationRule
{
    public function __construct(private readonly int|float $value) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_numeric($value)) {
            return;
        }

        if (!((float) $value > $this->value)) {
            $fail('validation.gt.numeric')->translate(['value' => $this->value]);
        }
    }
}
