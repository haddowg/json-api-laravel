<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Enforces a core {@see \haddowg\JsonApi\Resource\Constraint\AtLeastOneOf} — the value
 * must satisfy at least one of several alternative rule-sets. Laravel ships no
 * OR-combinator, so this evaluates each alternative in turn and passes as soon as one
 * does; only when every alternative fails does it report a violation.
 */
final class AtLeastOneOf implements ValidationRule
{
    /**
     * @param list<list<mixed>> $alternatives each alternative is a translated Laravel rule-set
     */
    public function __construct(private readonly array $alternatives) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($this->alternatives === []) {
            return;
        }

        foreach ($this->alternatives as $alternative) {
            // Validate under a FIXED, non-dotted key (like {@see EachElement} does) rather
            // than the outer `$attribute`: for a {@see \haddowg\JsonApi\Resource\Field\Map}
            // child the outer attribute is dotted (`address.postcode`), and Laravel would
            // read the rule key as a nested path into the flat data array — never finding
            // the value, silently passing every alternative. A fixed key sidesteps the
            // dotted-key ambiguity so the alternatives never depend on the attribute name.
            $validator = Validator::make(
                ['value' => $value],
                ['value' => $alternative],
            );

            if ($validator->passes()) {
                return;
            }
        }

        $fail('The :attribute field does not satisfy any of the allowed alternatives.');
    }
}
