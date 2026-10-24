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
 * (usable standalone or nested inside another composition). Each violation is reported
 * under the element's key, so it maps to `/data/attributes/<field>/<index>`. A non-array
 * value is left to the `array`/type layers and skipped.
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

        foreach ($value as $index => $element) {
            $errors = Validator::make(
                ['element' => $element],
                ['element' => $this->rules],
            )->errors();

            // Report under the element's own key (`tags.0`, or `tags.0.1` for a nested
            // Each), so the error points at the item rather than the whole array.
            foreach ($errors->keys() as $key) {
                foreach ($errors->get($key) as $message) {
                    $fail($attribute . '.' . $index . \substr((string) $key, \strlen('element')), \is_string($message) ? $message : '');
                }
            }
        }
    }
}
