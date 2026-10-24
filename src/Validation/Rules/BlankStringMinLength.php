<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\MinLength} on an empty or
 * whitespace-only string. Laravel skips every non-implicit rule for such a value, so the
 * `min:N` the translator emits never sees `""` and an empty string would pass a
 * `minLength(1)` field. This implicit rule checks only the strings Laravel skips; every
 * other value is left to `string` and `min:N`.
 */
final class BlankStringMinLength implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly int $min) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value) || \trim($value) !== '') {
            return;
        }

        if (\mb_strlen($value) < $this->min) {
            $fail('validation.min.string')->translate(['min' => (string) $this->min]);
        }
    }
}
