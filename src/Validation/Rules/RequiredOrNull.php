<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The `required` check for a field that is both required and nullable: an explicit
 * `null` passes, and any other value must pass Laravel's `required` (not an empty or
 * whitespace-only string, not an empty list or map). `false` and `0` pass.
 *
 * Laravel's own `required` rejects `null` whatever `nullable` says, so a required and
 * nullable field could never be cleared. The rule is implicit because Laravel only runs
 * implicit rules on an empty string, which this rule has to reject. Presence is left to
 * the `present` (create) or `sometimes` (update) rule beside it.
 */
final class RequiredOrNull implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        $blank = (\is_string($value) && \trim($value) === '')
            || (\is_countable($value) && \count($value) < 1);

        if ($blank) {
            $fail('validation.required')->translate();
        }
    }
}
