<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\UniqueItems} — every
 * element of the array is distinct. Laravel's native `distinct` is a *wildcard* rule
 * (`field.*`) that only fires as part of a nested array validation, so it cannot be
 * attached to the array attribute itself; this ships an attribute-level equivalent. A
 * non-array value is left to the `array`/type layers and skipped.
 */
final class DistinctArray implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_array($value)) {
            return;
        }

        $seen = [];
        foreach ($value as $item) {
            $key = \is_scalar($item) ? \gettype($item) . ':' . $item : \serialize($item);
            if (isset($seen[$key])) {
                $fail('validation.distinct')->translate();

                return;
            }
            $seen[$key] = true;
        }
    }
}
