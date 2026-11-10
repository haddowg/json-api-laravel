<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The `required` check for a field whose empty value is still a value: a number, a
 * boolean, a `OneOf` or a `Union`. It fails an absent or `null` value with Laravel's
 * `required` message, and passes `""`, `[]`, `false` and `0`, which the field's own type
 * check and value rules judge instead.
 *
 * It is implicit so Laravel runs it on an absent attribute and an empty string.
 */
final class PresentNotNull implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value === null) {
            $fail('validation.required')->translate();
        }
    }
}
