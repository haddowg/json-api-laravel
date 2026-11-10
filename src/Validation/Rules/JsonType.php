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
 * checked. A union's types, joined by `|`, are named together, since Laravel has no
 * message for a value of several types.
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

        if (\str_contains($this->type, '|')) {
            $fail('The :attribute field must be ' . self::oneOf(\explode('|', $this->type)) . '.');

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

    /**
     * The types a union takes, as a sentence: `a string, a number or a list`.
     *
     * @param list<string> $types
     */
    private static function oneOf(array $types): string
    {
        $words = \array_map(static fn(string $type): string => match ($type) {
            ValueTypes::BOOLEAN => 'a boolean',
            ValueTypes::INTEGER => 'an integer',
            ValueTypes::NUMBER => 'a number',
            ValueTypes::OBJECT => 'an object',
            ValueTypes::ARRAY => 'a list',
            default => 'a string',
        }, $types);
        $last = \array_pop($words) ?? '';

        return $words === [] ? $last : \implode(', ', $words) . ' or ' . $last;
    }
}
