<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Resource\Field\DateTime;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Runs a date field's rule only on a value the field accepts. {@see ParsableDate} already
 * answers a string the field refuses, so a bound never judges `next year` as well.
 */
final class OnlyParsableDates implements ValidationRule
{
    public function __construct(
        private readonly DateTime $field,
        private readonly ValidationRule $rule,
    ) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (\is_string($value) && $value !== '' && $this->field->tryParseWire($value) === null) {
            return;
        }

        $this->rule->validate($attribute, $value, $fail);
    }
}
