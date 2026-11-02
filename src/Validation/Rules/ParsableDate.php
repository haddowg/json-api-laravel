<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use haddowg\JsonApi\Resource\DateTimeParser;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a present, non-empty date-string that cannot be parsed — the write-body twin of
 * {@see \haddowg\JsonApiLaravel\Validation\FilterValueValidator::dateRangeMessages()}. A
 * {@see \haddowg\JsonApi\Resource\Field\DateTime} (and its {@see \haddowg\JsonApi\Resource\Field\Date}/Time
 * specialisations) carries no implicit format rule, so an unparseable value like
 * `"banana"` would otherwise pass document validation and reach core's hydration, where
 * core's `DateTime` cast rejects it. This attaches to every writable DateTime field so a
 * calendar-garbage or impossible value (`2026-02-30`) is a clean `422` at
 * `/data/attributes/<name>` before hydration.
 *
 * It parses exactly as core's cast does, with {@see DateTimeParser}, so the bridge admits
 * precisely the values core will accept. An absent, empty or non-string
 * value is skipped: presence is the required/`NotNull` rule's concern, and a non-string is
 * left to any typed rule — this rule speaks only to temporal validity.
 */
final class ParsableDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value) || $value === '') {
            return;
        }

        if (DateTimeParser::tryParse($value) === null) {
            $fail('validation.date')->translate();
        }
    }
}
