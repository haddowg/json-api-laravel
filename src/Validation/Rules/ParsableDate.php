<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Resource\Field\DateTime;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a present, non-empty date-string the field does not accept: one in another shape
 * than the field documents (`last year`, a date-time with no offset), or one naming an
 * impossible day (`2026-02-30`). The write-body twin of
 * {@see \haddowg\JsonApiLaravel\Validation\FilterValueValidator::dateRangeMessages()}. A
 * {@see DateTime} (and its {@see \haddowg\JsonApi\Resource\Field\Date}/Time
 * specialisations) carries no implicit format rule, so such a value would otherwise pass
 * document validation and reach core's hydration, where core's `DateTime` cast rejects it.
 * This attaches to every writable DateTime field, a field with a `fillUsing()` hook
 * included, so the value is a clean `422` at `/data/attributes/<name>` before hydration.
 *
 * It parses exactly as core's cast does, with the field's {@see DateTime::parseWire()}, so
 * the bridge admits precisely the values core will accept. An absent, empty or non-string
 * value is skipped: presence is the required/`NotNull` rule's concern, and a non-string is
 * left to any typed rule. This rule speaks only to temporal validity.
 */
final class ParsableDate implements ValidationRule
{
    public function __construct(private readonly DateTime $field) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value) || $value === '') {
            return;
        }

        if ($this->field->tryParseWire($value) === null) {
            $fail('validation.date')->translate();
        }
    }
}
