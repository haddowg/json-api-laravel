<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Resource\ValueTypes;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a value whose JSON type is not the one core's {@see ValueTypes} names for its
 * field, with Laravel's own message for that type. Laravel's `boolean`, `integer` and
 * `numeric` rules accept strings such as `"1"`, so they cannot check a JSON type
 * themselves. It is implicit so a blank string sent for a non-string field is still
 * checked.
 */
final class JsonType implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(
        private readonly string $type,
    ) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (ValueTypes::matches($this->type, $value)) {
            return;
        }

        $fail(match ($this->type) {
            ValueTypes::BOOLEAN => 'validation.boolean',
            ValueTypes::INTEGER => 'validation.integer',
            ValueTypes::NUMBER => 'validation.numeric',
            ValueTypes::OBJECT => 'validation.array',
            ValueTypes::ARRAY => 'validation.list',
            default => 'validation.string',
        })->translate();
    }
}
