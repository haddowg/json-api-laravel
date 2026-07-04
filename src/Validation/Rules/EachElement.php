<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\Each} — every element of
 * an array is validated against the same (translated) inner rules. Laravel expresses
 * per-element validation through a `field.*` wildcard key, which a single composable
 * rule cannot register on its own, so this ships the array-element loop as one rule
 * (usable standalone or nested inside another composition). A non-array value is left
 * to the `array`/type layers and skipped.
 */
final class EachElement implements ValidationRule
{
    /**
     * @param list<mixed> $rules the translated Laravel rules each element must satisfy
     */
    public function __construct(private readonly array $rules) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach (\array_values($value) as $element) {
            $validator = Validator::make(
                ['element' => $element],
                ['element' => $this->rules],
            );

            foreach ($validator->errors()->all() as $message) {
                $fail($message);
            }
        }
    }
}
