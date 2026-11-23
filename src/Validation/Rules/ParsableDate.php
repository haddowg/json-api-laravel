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
 *
 * Its message is core's {@see DateTime::dateMessage()}, the one the cast and a date range
 * bound report too. The rule runs under whatever key the validator gives the value, which
 * is a placeholder for a union member or an open object's member, so the message carries
 * {@see SUBJECT} and the {@see \haddowg\JsonApiLaravel\Validation\ResourceValidator}
 * names the attribute through {@see named()} once it knows the error's pointer.
 */
final class ParsableDate implements ValidationRule
{
    /**
     * Stands for the attribute in the message until the error's pointer is known.
     */
    public const string SUBJECT = '{date-subject}';

    public function __construct(private readonly DateTime $field) {}

    /**
     * `$detail` with the attribute it is about named as `$subject`: the pointer below
     * `/data/attributes`, such as `window/from`. Any other detail is returned unchanged.
     */
    public static function named(string $detail, string $subject): string
    {
        return \str_replace(self::SUBJECT, $subject, $detail);
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value) || $value === '') {
            return;
        }

        if ($this->field->tryParseWire($value) === null) {
            $fail($this->field->dateMessage()->for(self::SUBJECT));
        }
    }
}
